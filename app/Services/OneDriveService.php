<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class OneDriveService
{
    protected string $accessToken;

    public function __construct()
    {
        $this->accessToken = $this->fetchAccessToken();
    }

   protected function fetchAccessToken(): string
{
    $tenant = config('services.onedrive.tenant_id');
    $relayUrl = config('services.onedrive.relay_url');
    $relaySecret = config('services.onedrive.relay_secret');

    $tokenPath = "/{$tenant}/oauth2/v2.0/token";

    $request = Http::asForm();

    if ($relayUrl) {
        // IONOS blocks outbound requests to login.microsoftonline.com, so route
        // the token refresh through a Cloudflare Worker relay instead.
        $request = $request->withHeaders([
            'X-Relay-Auth' => $relaySecret,
            'X-Relay-Target' => 'login',
        ]);
        $url = rtrim($relayUrl, '/') . $tokenPath;
    } else {
        $url = "https://login.microsoftonline.com{$tokenPath}";
    }

    $response = $request->post(
        $url,
        [
            'client_id'     => config('services.onedrive.client_id'),
            'client_secret' => config('services.onedrive.client_secret'),
            'refresh_token' => config('services.onedrive.refresh_token'),
            'grant_type'    => 'refresh_token',
        ]
    );

    $body = mb_convert_encoding($response->body(), 'UTF-8', 'UTF-8');
    $json = json_decode($body, true);

    if (!isset($json['access_token'])) {
       \Log::error('OneDrive token refresh failed', [
            'response' => preg_replace('//u', '', $body)
        ]);
        throw new \Exception('OneDrive authentication failed. Check credentials.');
    }

    return $json['access_token'];
}


    public function upload(string $path, string $contents)
    {
        return Http::withToken($this->accessToken)
            ->withHeaders([
                'Content-Type' => 'application/octet-stream', // important for binary files
            ])
            ->send('PUT', "https://graph.microsoft.com/v1.0/me/drive/root:/{$path}:/content", [
                'body' => $contents
            ]);
    }
    
    
    public function getImageUrl(string $path)
    {
        $response = Http::withToken($this->accessToken)
            ->post("https://graph.microsoft.com/v1.0/me/drive/root:/{$path}:/createLink", [
                'type' => 'view',   // 'view' for read-only link
                'scope' => 'anonymous' // anyone with link can view
            ]);
    
        $json = $response->json();
    
        if (!isset($json['link']['webUrl'])) {
            throw new \Exception('Failed to generate OneDrive share link');
        }
    
        return $json['link']['webUrl']; // this is your <img> URL
    }
    
    
    public function getDirectFileUrl(string $path)
    {
        // Get metadata for the file
        $response = Http::withToken($this->accessToken)
            ->get("https://graph.microsoft.com/v1.0/me/drive/root:/{$path}");
    
        $json = $response->json();
    
        if (!isset($json['@microsoft.graph.downloadUrl'])) {
            throw new \Exception('Failed to get direct download URL');
        }
    
        return $json['@microsoft.graph.downloadUrl'];
    }
    
    
    /**
     * File metadata only (name, size) — a few hundred bytes, unlike
     * streamFile() which buffers the whole file. Lets a caller check that a
     * file exists and how big it is before committing memory to it.
     */
    public function fileMetadata(string $path): array
    {
        $response = Http::withToken($this->accessToken)
            ->timeout(20)
            ->get("https://graph.microsoft.com/v1.0/me/drive/root:/{$path}");

        return [
            'ok'      => $response->successful(),
            'status'  => $response->status(),
            'name'    => $response->json('name'),
            'size'    => $response->json('size'),
            'error'   => $response->failed() ? $response->json('error.message') : null,
        ];
    }

    public function streamFile(string $path)
    {
        $response = Http::withToken($this->accessToken)
            ->timeout(30)
            ->get("https://graph.microsoft.com/v1.0/me/drive/root:/{$path}:/content");

        // Graph returns JSON on failure (401 expired token, 404 missing file).
        // Without this check that error body was handed back as if it were
        // image bytes, so the browser just showed a broken image and the real
        // reason never surfaced anywhere.
        if ($response->failed()) {
            $detail = $response->json('error.message') ?? $response->body();
            throw new Exception(
                "OneDrive returned {$response->status()} for {$path}: " . mb_substr((string) $detail, 0, 300)
            );
        }

        return $response->body();
    }

    public function delete(string $path)
    {
        try {
            $response = Http::withToken($this->accessToken)
                ->delete("https://graph.microsoft.com/v1.0/me/drive/root:/{$path}");

            if ($response->failed()) {
                \Log::error('OneDrive file delete failed', [
                    'path' => $path,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                throw new \Exception("Failed to delete OneDrive file: {$path}");
            }

            return true; // Successfully deleted
        } catch (Exception $e) {
            \Log::error('OneDrive delete exception', [
                'path' => $path,
                'message' => $e->getMessage()
            ]);
            throw $e;
        }
    }

}
