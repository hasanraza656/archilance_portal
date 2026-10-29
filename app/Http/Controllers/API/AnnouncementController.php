<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Support\PayrollSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * "What's new" popups: remembers, per person, which announcements they have already seen, so each one appears once.
 * Until the `announcements` database update is applied, nothing is stored (the page then remembers it in the browser).
 */
class AnnouncementController extends Controller
{
    private function ready(): bool
    {
        return PayrollSchema::table('announcement_views');
    }

    private function cleanKey(string $key): string
    {
        return mb_substr(preg_replace('/[^A-Za-z0-9._-]/', '', $key), 0, 80);
    }

    /** GET /announcements/seen — the announcement keys this person has already seen. */
    public function seen()
    {
        if (!$this->ready()) {
            return response()->json(['supported' => false, 'seen' => []]);
        }

        return response()->json([
            'supported' => true,
            'seen' => DB::table('announcement_views')->where('user_id', Auth::id())->pluck('announcement_key')->values(),
        ]);
    }

    /** POST /announcements/{key}/seen — idempotent. */
    public function markSeen(Request $request, string $key)
    {
        $key = $this->cleanKey($key);
        if ($key === '') {
            return response()->json(['message' => 'Unknown announcement.'], 422);
        }
        if (!$this->ready()) {
            return response()->json(['supported' => false]);
        }

        try {
            DB::table('announcement_views')->insertOrIgnore(['user_id' => Auth::id(), 'announcement_key' => $key, 'seen_at' => now()]);
        } catch (\Throwable $e) {
            // never block closing a popup
        }

        return response()->json(['supported' => true, 'seen' => true]);
    }
}
