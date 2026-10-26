<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ChatStorage;
use App\Support\ChatSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Chat files on OneDrive - any size up to MAX_BYTES.
 *
 * Uploading: the browser cuts the file into 5 MiB pieces and sends them in order. Each piece goes straight
 * on to a OneDrive upload session, so this server only ever holds one piece in memory and no PHP size
 * limit applies to the file as a whole. A broken connection resumes from the last piece OneDrive confirmed.
 *
 * Downloading: signed links (made by ChatCore::fileUrl) redirect to a short-lived OneDrive download URL, so
 * big downloads never pass through this server either. PDFs additionally get a byte-range proxy so the
 * viewer can open a huge PDF by reading only the pages on screen.
 */
class ChatFileController extends Controller
{
    /** 2 GiB per file (OneDrive itself allows far more). */
    public const MAX_BYTES = 2147483648;

    private function unsupported()
    {
        return response()->json(['supported' => false, 'message' => 'Chat v2 needs a database update (System settings -> Update database).'], 503);
    }

    private function safeName(string $name): string
    {
        $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '_', $name) ?: 'file';
        $name = trim($name, " .");

        return mb_substr($name !== '' ? $name : 'file', 0, 150);
    }

    /** POST /api/chat/v2/uploads {file_name, file_size, mime} */
    public function init(Request $request, ChatStorage $storage)
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }

        $data = $request->validate([
            'file_name' => 'required|string|max:255',
            'file_size' => 'required|integer|min:1|max:' . self::MAX_BYTES,
            'mime' => 'nullable|string|max:120',
        ]);

        $me = Auth::id();
        $id = Str::random(32);
        $remote = 'chat_files/' . $me . '/' . now()->format('Ymd') . '/' . substr($id, 0, 8) . '_' . $this->safeName($data['file_name']);

        try {
            $url = $storage->createUploadSession($remote);
        } catch (\Throwable $e) {
            \Log::error('Chat upload init failed: ' . $e->getMessage());

            return response()->json(['message' => 'Could not start the upload. Please try again in a moment.'], 502);
        }

        DB::table('chat_uploads')->insert([
            'id' => $id, 'user_id' => $me, 'file_name' => $this->safeName($data['file_name']), 'mime' => $data['mime'] ?? null,
            'file_size' => $data['file_size'], 'remote_path' => $remote, 'upload_url' => $url, 'bytes_received' => 0,
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['upload_id' => $id, 'chunk_bytes' => ChatStorage::CHUNK_BYTES, 'file_size' => (int) $data['file_size']]);
    }

    /**
     * POST /api/chat/v2/uploads/{id}/chunk   (raw body; header X-Chunk-Start = byte offset of this piece)
     * Replying with bytes_received lets the client resume exactly where it stopped.
     */
    public function chunk(Request $request, $id, ChatStorage $storage)
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }

        $up = DB::table('chat_uploads')->where('id', $id)->where('user_id', Auth::id())->first();
        if (!$up) {
            return response()->json(['message' => 'Unknown upload.'], 404);
        }
        if ($up->status === 'done') {
            return response()->json(['done' => true, 'bytes_received' => (int) $up->file_size]);
        }
        if ($up->status !== 'open') {
            return response()->json(['message' => 'This upload was cancelled.'], 409);
        }

        $start = (int) $request->header('X-Chunk-Start', -1);
        $body = $request->getContent();
        $len = strlen($body);

        if ($start !== (int) $up->bytes_received) {
            // A retried piece we already have (or an out-of-order one): tell the client where to continue from.
            return response()->json(['done' => false, 'bytes_received' => (int) $up->bytes_received, 'resync' => true], 409);
        }
        if ($len < 1 || $start + $len > (int) $up->file_size) {
            return response()->json(['message' => 'Bad piece size.'], 422);
        }
        $isLast = $start + $len === (int) $up->file_size;
        if (!$isLast && $len % (320 * 1024) !== 0) {
            return response()->json(['message' => 'Pieces must be a multiple of 320 KiB except the last.'], 422);
        }

        try {
            $res = $storage->putChunk($up->upload_url, $body, $start, (int) $up->file_size);
        } catch (\Throwable $e) {
            \Log::warning('Chat chunk failed: ' . $e->getMessage());

            return response()->json(['message' => 'OneDrive did not accept that piece - it will be retried.'], 502);
        }

        $update = ['bytes_received' => $start + $len, 'updated_at' => now()];
        if ($res['done']) {
            $update['status'] = 'done';
            // OneDrive may have renamed the file to avoid a clash; keep the real final path.
            $name = $res['item']['name'] ?? null;
            if ($name) {
                $update['remote_path'] = dirname($up->remote_path) . '/' . $name;
            }
        }
        DB::table('chat_uploads')->where('id', $id)->update($update);

        return response()->json(['done' => (bool) $res['done'], 'bytes_received' => $start + $len]);
    }

    /** GET /api/chat/v2/uploads/{id} - where did we get to (resume after a refresh / lost connection) */
    public function status($id)
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }
        $up = DB::table('chat_uploads')->where('id', $id)->where('user_id', Auth::id())->first();
        if (!$up) {
            return response()->json(['message' => 'Unknown upload.'], 404);
        }

        return response()->json(['status' => $up->status, 'bytes_received' => (int) $up->bytes_received, 'file_size' => (int) $up->file_size]);
    }

    /** DELETE /api/chat/v2/uploads/{id} */
    public function cancel($id, ChatStorage $storage)
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }
        $up = DB::table('chat_uploads')->where('id', $id)->where('user_id', Auth::id())->first();
        if ($up && $up->status === 'open') {
            if ($up->upload_url) {
                $storage->cancelUploadSession($up->upload_url);
            }
            DB::table('chat_uploads')->where('id', $id)->update(['status' => 'failed', 'updated_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    /** POST /api/chat/v2/uploads/thumb (multipart file: a small JPEG/PNG preview made in the browser) */
    public function thumb(Request $request, ChatStorage $storage)
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }

        $request->validate(['file' => 'required|file|mimes:jpg,jpeg,png,webp|max:2048']);
        $file = $request->file('file');
        $path = 'chat_thumbs/' . Auth::id() . '/' . now()->format('Ymd') . '/' . Str::random(16) . '.' . ($file->getClientOriginalExtension() ?: 'jpg');

        try {
            $storage->putSmall($path, file_get_contents($file->getRealPath()));
        } catch (\Throwable $e) {
            \Log::warning('Chat thumb upload failed: ' . $e->getMessage());

            return response()->json(['message' => 'Preview upload failed.'], 502);
        }

        return response()->json(['thumb_path' => $path]);
    }

    /* ----------------------------------------------------------------------------------------------
     * Serving files (links are signed and expire - see ChatCore::fileUrl)
     * -------------------------------------------------------------------------------------------- */

    /** GET /api/chat/v2/files/{attachment}?v=full|thumb|stream */
    public function file(Request $request, $attachment, ChatStorage $storage)
    {
        $att = DB::table('chat_attachments')->where('id', $attachment)->first();
        if (!$att) {
            abort(404);
        }

        $variant = $request->query('v', 'full');
        $path = $variant === 'thumb' && !empty($att->thumb_path) ? $att->thumb_path : $att->file_path;

        // A lower-quality copy of a video (the link was signed for exactly this copy).
        $qualityId = (int) $request->query('var', 0);
        if ($qualityId > 0) {
            $copy = ChatSchema::video() ? DB::table('chat_attachment_variants')->where('id', $qualityId)->where('attachment_id', $att->id)->first() : null;
            if (!$copy) {
                abort(404);
            }
            $path = $copy->file_path;
            $variant = 'full';
        }
        if (!$path) {
            abort(404);
        }

        // Old (pre-v2) attachments on the web server's own disk rather than OneDrive.
        if (!empty($att->created_at) && $att->created_at < '2026-01-10' && \Illuminate\Support\Facades\Storage::disk('public')->exists($path)) {
            return \Illuminate\Support\Facades\Storage::disk('public')->response($path);
        }

        try {
            if ($variant === 'stream') {
                return $this->rangeProxy($request, $storage, $path, $att);
            }

            return redirect()->away($storage->downloadUrl($path))->header('Cache-Control', 'private, max-age=1200');
        } catch (\Throwable $e) {
            \Log::warning('Chat file fetch failed: ' . $e->getMessage(), ['attachment' => $attachment]);
            abort(404);
        }
    }

    /**
     * POST /api/chat/v2/attachments/{id}/variants  {upload_id, label, width, height, bitrate}
     *
     * The sender's browser makes lower-quality copies of a video (720p / 480p / 360p) AFTER the message is sent, uploads
     * each one with the normal upload flow, then registers it here so the player can switch down on a slow connection.
     */
    public function registerVariant(Request $request, $id)
    {
        if (!ChatSchema::ready()) {
            return response()->json(['supported' => false], 503);
        }
        if (!ChatSchema::video()) {
            return response()->json(['supported' => false, 'message' => 'Video quality copies need the "chat_video" database update.'], 503);
        }

        $me = Auth::user();
        $data = $request->validate([
            'upload_id' => 'required|string',
            'label' => ['required', 'string', 'regex:/^\d{3,4}p$/'],
            'width' => 'nullable|integer|min:1|max:8000',
            'height' => 'nullable|integer|min:1|max:5000',
            'bitrate' => 'nullable|integer|min:1000|max:100000000',
        ]);

        $att = DB::table('chat_attachments')->where('id', $id)->first();
        $chat = $att ? DB::table('chats')->where('id', $att->chat_id)->first() : null;
        if (!$att || !$chat || (int) $chat->sender_id !== (int) $me->id) {
            return response()->json(['message' => 'Only the person who sent the video can add copies of it.'], 403);
        }
        if (\App\Services\ChatCore::kindOf($att->file_type ?? null, $att->file_name ?? null) !== 'video') {
            return response()->json(['message' => 'This is not a video.'], 422);
        }

        $up = DB::table('chat_uploads')->where('id', $data['upload_id'])->where('user_id', $me->id)->first();
        if (!$up || $up->status !== 'done') {
            return response()->json(['message' => 'The copy has not finished uploading.'], 422);
        }

        $row = [
            'file_path' => $up->remote_path,
            'mime' => $up->mime,
            'file_size' => $up->file_size,
            'width' => $data['width'] ?? null,
            'height' => $data['height'] ?? null,
            'bitrate' => $data['bitrate'] ?? null,
            'updated_at' => now(),
        ];
        $exists = DB::table('chat_attachment_variants')->where('attachment_id', $att->id)->where('label', $data['label'])->first();
        if ($exists) {
            DB::table('chat_attachment_variants')->where('id', $exists->id)->update($row);
        } else {
            DB::table('chat_attachment_variants')->insert($row + ['attachment_id' => $att->id, 'label' => $data['label'], 'created_at' => now()]);
        }

        $all = DB::table('chat_attachment_variants')->where('attachment_id', $att->id)->get();

        return response()->json(['attachment' => \App\Services\ChatCore::presentAttachment($att, $all)]);
    }

    /** Serves just the bytes a PDF viewer asks for (HTTP Range), so opening a 300 MB PDF reads a few hundred KB. */
    private function rangeProxy(Request $request, ChatStorage $storage, string $path, object $att)
    {
        $range = $request->header('Range');
        if (!$range || !preg_match('/bytes=(\d+)-(\d*)/', $range, $m)) {
            // No Range header: a small file is relayed whole (so a browser can read it without cross-origin trouble),
            // anything bigger is handed to OneDrive directly.
            $size = (int) ($att->file_size ?? 0);
            if ($size > 0 && $size <= 8 * 1024 * 1024) {
                $r = $storage->readRange($path, 0, $size - 1);
                if (in_array($r['status'], [200, 206], true)) {
                    return response($r['body'], 200, [
                        'Content-Type' => $att->file_type ?: 'application/octet-stream',
                        'Accept-Ranges' => 'bytes',
                        'Cache-Control' => 'private, max-age=3000',
                    ]);
                }
            }

            return redirect()->away($storage->downloadUrl($path));
        }

        $start = (int) $m[1];
        $end = $m[2] !== '' ? (int) $m[2] : $start + 1024 * 1024 - 1;
        $end = min($end, $start + 8 * 1024 * 1024 - 1); // never relay more than 8 MiB per request

        $r = $storage->readRange($path, $start, $end);

        return response($r['body'], $r['status'] === 206 || $r['status'] === 200 ? 206 : $r['status'], array_filter([
            'Content-Type' => $att->file_type ?: 'application/octet-stream',
            'Accept-Ranges' => 'bytes',
            'Content-Range' => $r['content_range'],
            'Cache-Control' => 'private, max-age=3000',
        ]));
    }
}
