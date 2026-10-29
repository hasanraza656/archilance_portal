<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WorkSession;
use App\Services\OneDriveService;
use App\Services\WorkSessionProof;
use Illuminate\Support\Facades\Auth;

/**
 * Download the attachment of a manual time entry. Authenticated (the browser fetches it with the bearer token), and
 * limited to the owner of the time and to the people who review work diaries.
 */
class WorkSessionProofController extends Controller
{
    /** How large an attachment this server accepts, so the form can say so BEFORE a big file is sent. */
    public function limits()
    {
        $bytes = WorkSessionProof::maxBytes();

        return response()->json([
            'max_bytes' => $bytes,
            'max_mb' => round($bytes / 1048576, 1),
            'server_limited' => $bytes < (WorkSessionProof::MAX_KB * 1024 - 256 * 1024),
            'ready' => WorkSessionProof::ready(),
        ]);
    }

    public function download($id)
    {
        $session = WorkSession::findOrFail($id);

        if (!WorkSessionProof::canView(Auth::user(), $session)) {
            return response()->json(['message' => 'You are not allowed to open this attachment.'], 403);
        }

        $path = $session->getAttributes()['proof_path'] ?? null;
        if (!$path) {
            return response()->json(['message' => 'This entry has no attachment.'], 404);
        }

        try {
            $contents = app(OneDriveService::class)->streamFile($path);
        } catch (\Throwable $e) {
            \Log::warning('Work session proof download failed for session ' . $session->id . ': ' . $e->getMessage());

            return response()->json(['message' => 'The attachment could not be fetched right now. Please try again.'], 502);
        }

        $name = $session->proof_name ?: basename($path);

        return response($contents, 200, [
            'Content-Type' => $session->proof_type ?: 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . str_replace(['"', "\r", "\n"], '', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
