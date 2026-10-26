<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * OneDrive access for chat files, built for BIG files.
 *
 * Why not OneDriveService directly: its constructor fetches a fresh access token on every instantiation
 * (a network round-trip each time) and its upload()/streamFile() hold the whole file in PHP memory - fine
 * for a screenshot, impossible for a 1 GB drawing set. This class
 *   - caches the access token (about 50 minutes), so 100+ chunk requests do not each re-authenticate;
 *   - uploads through a Graph UPLOAD SESSION, one chunk at a time (memory stays at one chunk);
 *   - hands out short-lived direct-download URLs, so the browser downloads from OneDrive itself and the
 *     file never flows through this server;
 *   - can read a byte RANGE, which is what lets the PDF viewer open a huge PDF without downloading it all.
 */
class ChatStorage extends OneDriveService
{
    /** 5 MiB = 16 x 320 KiB, the multiple Graph requires for every chunk except the last. */
    public const CHUNK_BYTES = 5 * 1024 * 1024;

    private const TOKEN_KEY = 'chat_storage_onedrive_token';

    public function __construct()
    {
        // Deliberately NOT calling parent::__construct(): the token is fetched lazily and cached.
    }

    public function token(): string
    {
        $cached = Cache::get(self::TOKEN_KEY);
        if ($cached) {
            return $cached;
        }

        $token = $this->fetchAccessToken();
        Cache::put(self::TOKEN_KEY, $token, now()->addMinutes(50));

        return $token;
    }

    private function graph()
    {
        return Http::withToken($this->token());
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /** Starts a resumable upload; returns the pre-authenticated upload URL. */
    public function createUploadSession(string $path): string
    {
        $res = $this->graph()
            ->timeout(30)
            ->post('https://graph.microsoft.com/v1.0/me/drive/root:/' . $this->encodePath($path) . ':/createUploadSession', [
                'item' => ['@microsoft.graph.conflictBehavior' => 'rename'],
            ]);

        $url = $res->json('uploadUrl');
        if ($res->failed() || !$url) {
            throw new \RuntimeException('OneDrive refused to start the upload: ' . mb_substr((string) ($res->json('error.message') ?? $res->body()), 0, 200));
        }

        return $url;
    }

    /**
     * Sends one chunk. Returns ['done' => bool, 'status' => int, 'item' => array|null].
     * The upload URL carries its own authorisation, so no bearer token is sent.
     */
    public function putChunk(string $uploadUrl, string $bytes, int $start, int $total): array
    {
        $len = strlen($bytes);
        $end = $start + $len - 1;

        $res = Http::withHeaders([
            'Content-Length' => (string) $len,
            'Content-Range' => "bytes {$start}-{$end}/{$total}",
        ])->withBody($bytes, 'application/octet-stream')
            ->timeout(120)
            ->put($uploadUrl);

        if (in_array($res->status(), [200, 201], true)) {
            return ['done' => true, 'status' => $res->status(), 'item' => $res->json()];
        }
        if ($res->status() === 202) {
            return ['done' => false, 'status' => 202, 'item' => null];
        }

        throw new \RuntimeException('OneDrive chunk failed (' . $res->status() . '): ' . mb_substr((string) ($res->json('error.message') ?? $res->body()), 0, 200));
    }

    public function cancelUploadSession(string $uploadUrl): void
    {
        try {
            Http::timeout(15)->delete($uploadUrl);
        } catch (\Throwable $e) {
            // best effort
        }
    }

    /** Small file in one request (thumbnails, voice notes under a few MB). */
    public function putSmall(string $path, string $bytes): void
    {
        $res = $this->graph()
            ->withBody($bytes, 'application/octet-stream')
            ->timeout(60)
            ->put('https://graph.microsoft.com/v1.0/me/drive/root:/' . $this->encodePath($path) . ':/content');

        if ($res->failed()) {
            throw new \RuntimeException('OneDrive upload failed (' . $res->status() . '): ' . mb_substr((string) ($res->json('error.message') ?? $res->body()), 0, 200));
        }
    }

    /** Pre-authenticated direct download URL (valid about an hour; cached for 25 minutes). */
    public function downloadUrl(string $path): string
    {
        return Cache::remember('chat_dl_' . md5($path), now()->addMinutes(25), function () use ($path) {
            $res = $this->graph()
                ->timeout(20)
                ->get('https://graph.microsoft.com/v1.0/me/drive/root:/' . $this->encodePath($path));

            // NOT $res->json('@microsoft.graph.downloadUrl'): the dots in that key would be read as nesting.
            $url = ($res->json() ?: [])['@microsoft.graph.downloadUrl'] ?? null;
            if ($res->failed() || !$url) {
                throw new \RuntimeException('OneDrive could not find the file (' . $res->status() . ').');
            }

            return $url;
        });
    }

    /** Reads bytes [start, end] of a file (the whole file when start is null). */
    public function readRange(string $path, ?int $start, ?int $end): array
    {
        $headers = [];
        if ($start !== null) {
            $headers['Range'] = 'bytes=' . $start . '-' . ($end ?? '');
        }

        $res = Http::withHeaders($headers)->timeout(60)->get($this->downloadUrl($path));

        return [
            'status' => $res->status(),
            'body' => $res->body(),
            'content_range' => $res->header('Content-Range'),
            'length' => $res->header('Content-Length'),
            'type' => $res->header('Content-Type'),
        ];
    }

    public function remove(string $path): void
    {
        try {
            $this->graph()->timeout(20)->delete('https://graph.microsoft.com/v1.0/me/drive/root:/' . $this->encodePath($path));
        } catch (\Throwable $e) {
            \Log::warning('ChatStorage remove failed', ['path' => $path, 'error' => $e->getMessage()]);
        }
    }
}
