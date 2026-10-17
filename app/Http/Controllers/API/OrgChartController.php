<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\OrgChart;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Org Chart module — admins and executives only (mounted under both the admin and
 * the employee route groups; the check below is what actually gates it).
 *
 *   GET    /org-chart/people   everyone who belongs on the chart + their reporting line
 *   GET    /org-charts         saved charts (no snapshot)
 *   GET    /org-charts/{id}    one saved chart in full
 *   POST   /org-charts         save a chart (snapshot + changes report)
 *   DELETE /org-charts/{id}
 *
 * The chart is built in the browser from /people. Saving stores a snapshot so an
 * older chart can be re-opened and exported later exactly as it was.
 */
class OrgChartController extends Controller
{
    /** Test accounts that must never appear on the chart. */
    public const EXCLUDED_USER_IDS = [55, 238, 221, 194, 7, 6, 4, 5, 8];

    private function denied()
    {
        $u = Auth::user();
        $allowed = $u && ((int) $u->user_role === 2 || $u->employee_type === 'Executive');

        return $allowed ? null : response()->json(['message' => 'Only admins and executives can use the org chart.'], 403);
    }

    private function unsupported()
    {
        return Schema::hasTable('org_charts')
            ? null
            : response()->json(['supported' => false, 'message' => 'Saved charts are not available yet.', 'data' => []], 200);
    }

    public function people()
    {
        if ($r = $this->denied()) {
            return $r;
        }

        // Staff only: admins (2) and employees (3, any employee_type). Customers/members never belong here.
        $rows = User::whereIn('user_role', [2, 3])
            ->whereNotIn('id', self::EXCLUDED_USER_IDS)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'profile_pic', 'user_role', 'employee_type', 'employee_team', 'manager_id', 'internee_manager_id']);

        return response()->json(['data' => $rows]);
    }

    /**
     * One person's profile picture, served through the API.
     *
     * Exports draw the chart onto a canvas, which the browser only allows for images
     * that send CORS headers — static /storage files on the legacy host do not. API
     * responses always do, so the pictures come through here (local disk first, then
     * the legacy host that still holds the older uploads).
     */
    public function photo($id)
    {
        if ($r = $this->denied()) {
            return $r;
        }

        $pic = User::where('id', (int) $id)->value('profile_pic');
        if (!$pic || str_contains($pic, '..') || preg_match('#^https?://#i', $pic)) {
            return response()->json(['message' => 'No picture.'], 404);
        }

        $headers = ['Cache-Control' => 'private, max-age=3600'];

        try {
            $disk = Storage::disk('public');
            if ($disk->exists($pic)) {
                return response($disk->get($pic), 200, $headers + ['Content-Type' => $disk->mimeType($pic) ?: 'image/jpeg']);
            }
        } catch (\Throwable $e) {
            // fall through to the legacy host
        }

        try {
            $res = Http::timeout(15)->get('https://portal.archilance.net/backend/public/storage/' . ltrim($pic, '/'));
            $type = (string) $res->header('Content-Type');
            if ($res->successful() && str_starts_with($type, 'image/')) {
                return response($res->body(), 200, $headers + ['Content-Type' => $type]);
            }
        } catch (\Throwable $e) {
            // not reachable
        }

        return response()->json(['message' => 'Picture not found.'], 404);
    }

    public function index()
    {
        if ($r = $this->denied()) {
            return $r;
        }
        if ($r = $this->unsupported()) {
            return $r;
        }

        $rows = OrgChart::with('creator:id,name')->orderByDesc('id')->get()->map(fn ($c) => [
            'id' => $c->id,
            'title' => $c->title,
            'node_count' => $c->node_count,
            'change_count' => $c->change_count,
            'created_by' => $c->creator?->name,
            'created_at' => $c->created_at?->toIso8601String(),
        ]);

        return response()->json(['supported' => true, 'data' => $rows]);
    }

    public function show($id)
    {
        if ($r = $this->denied()) {
            return $r;
        }
        if (!Schema::hasTable('org_charts')) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $c = OrgChart::with('creator:id,name')->find($id);
        if (!$c) {
            return response()->json(['message' => 'Chart not found.'], 404);
        }

        return response()->json(['data' => [
            'id' => $c->id,
            'title' => $c->title,
            'snapshot' => $c->snapshot,
            'changes' => $c->changes,
            'created_by' => $c->creator?->name,
            'created_at' => $c->created_at?->toIso8601String(),
        ]]);
    }

    public function store(Request $request)
    {
        if ($r = $this->denied()) {
            return $r;
        }
        if (!Schema::hasTable('org_charts')) {
            return response()->json(['message' => 'Saved charts are not available yet.'], 503);
        }

        $v = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'snapshot' => 'required|array',
            'snapshot.nodes' => 'required|array|min:1|max:1000',
            'changes' => 'nullable|array',
        ]);
        if ($v->fails()) {
            return response()->json(['errors' => $v->errors()], 422);
        }

        $changes = $request->input('changes');
        $changeCount = 0;
        if (is_array($changes)) {
            foreach (['parentChanges', 'removed'] as $k) {
                $changeCount += count($changes[$k] ?? []);
            }
        }

        $chart = OrgChart::create([
            'title' => $request->input('title'),
            'snapshot' => $request->input('snapshot'),
            'changes' => $changes,
            'node_count' => count($request->input('snapshot.nodes')),
            'change_count' => $changeCount,
            'created_by' => Auth::id(),
        ]);

        return response()->json([
            'message' => 'Chart saved.',
            'data' => ['id' => $chart->id, 'created_at' => $chart->created_at?->toIso8601String()],
        ], 201);
    }

    public function destroy($id)
    {
        if ($r = $this->denied()) {
            return $r;
        }
        if (!Schema::hasTable('org_charts')) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $chart = OrgChart::find($id);
        if (!$chart) {
            return response()->json(['message' => 'Chart not found.'], 404);
        }
        $chart->delete();

        return response()->json(['message' => 'Chart deleted.']);
    }
}
