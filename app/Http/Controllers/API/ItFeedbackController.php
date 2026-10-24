<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ItFeedback;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Feedback for the IT team from everyone who works inside the portal (admins, executives,
 * managers, supervisors, employees, interns) — never customers or their team members.
 *
 *  - status / skip / submit : any eligible user, about THEMSELVES only
 *  - adminIndex             : admin only (routed under /api/admin), the only place answers are visible
 *
 * Until the `it_feedback` table exists the user-facing endpoints answer "not eligible", so
 * deploying this code before the database update changes nothing for anyone.
 */
class ItFeedbackController extends Controller
{
    /** user_role ids that may give feedback: admin, employee (incl. managers/executives/interns), supervisor, executive. */
    private const ELIGIBLE_ROLES = [1, 2, 3, 6, 7];

    /** key => question, in the order they are asked. Keep in sync with the v2 form. */
    private const QUESTIONS = [
        'overall' => 'How are we doing so far?',
        'satisfaction' => 'How satisfied are you with our service?',
        'communication' => 'How would you rate our communication?',
        'response' => 'How quickly do we respond to you?',
        'resolution' => 'Do we resolve your issues on time?',
    ];

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('it_feedback');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function eligible($user): bool
    {
        return $user && in_array((int) $user->user_role, self::ELIGIBLE_ROLES, true);
    }

    /** GET /api/it-feedback/status */
    public function status()
    {
        $user = Auth::user();

        if (!$this->tableReady() || !$this->eligible($user)) {
            return response()->json(['supported' => $this->tableReady(), 'eligible' => false, 'status' => null]);
        }

        $row = ItFeedback::where('user_id', $user->id)->first();

        // A person can always look back at what they themselves answered.
        $mine = null;
        if ($row && $row->status === 'submitted') {
            $mine = ['comment' => $row->comment, 'submitted_at' => $row->submitted_at?->toIso8601String()];
            foreach (array_keys(self::QUESTIONS) as $key) {
                $mine[$key] = (int) $row->{$key};
            }
        }

        return response()->json([
            'supported' => true,
            'eligible' => true,
            'status' => $row?->status ?? 'none',
            'mine' => $mine,
        ]);
    }

    /** POST /api/it-feedback/skip — the popup was dismissed; only the banner is shown from now on. */
    public function skip()
    {
        $user = Auth::user();

        if (!$this->tableReady() || !$this->eligible($user)) {
            return response()->json(['message' => 'Feedback is not available.'], 403);
        }

        $row = ItFeedback::firstOrCreate(
            ['user_id' => $user->id],
            ['status' => 'skipped', 'skipped_at' => now()]
        );

        // A submitted answer is never downgraded by a late "skip".
        return response()->json(['status' => $row->status]);
    }

    /** POST /api/it-feedback/submit */
    public function submit(Request $request)
    {
        $user = Auth::user();

        if (!$this->tableReady() || !$this->eligible($user)) {
            return response()->json(['message' => 'Feedback is not available.'], 403);
        }

        $rules = ['comment' => 'nullable|string|max:2000'];
        foreach (array_keys(self::QUESTIONS) as $key) {
            $rules[$key] = 'required|integer|min:1|max:5';
        }
        $data = $request->validate($rules);

        $row = ItFeedback::where('user_id', $user->id)->first();

        if ($row && $row->status === 'submitted') {
            return response()->json(['message' => 'You have already shared your feedback. Thank you!', 'status' => 'submitted'], 422);
        }

        $comment = isset($data['comment']) ? trim($data['comment']) : null;

        ItFeedback::updateOrCreate(
            ['user_id' => $user->id],
            array_merge(
                collect(array_keys(self::QUESTIONS))->mapWithKeys(fn ($k) => [$k => (int) $data[$k]])->all(),
                ['comment' => $comment !== '' ? $comment : null, 'status' => 'submitted', 'submitted_at' => now()]
            )
        );

        return response()->json(['message' => 'Thank you for your feedback!', 'status' => 'submitted']);
    }

    /** "Admin", "Executive", "Manager", … for display. */
    private function roleLabel(User $u): string
    {
        return match ((int) $u->user_role) {
            1, 2 => 'Admin',
            6 => 'Supervisor',
            7 => 'Executive',
            default => $u->employee_type ?: 'Employee',
        };
    }

    /** GET /api/admin/it-feedback — every answer, the averages, and who has not answered yet. */
    public function adminIndex()
    {
        if (!$this->tableReady()) {
            return response()->json(['supported' => false]);
        }

        $people = User::whereIn('user_role', self::ELIGIBLE_ROLES)
            ->get(['id', 'name', 'email', 'profile_pic', 'user_role', 'employee_type']);

        $rows = ItFeedback::with('user:id,name,email,profile_pic,user_role,employee_type,deleted_at')
            ->orderByDesc('submitted_at')
            ->get();

        $submitted = $rows->where('status', 'submitted')->values();
        $skippedIds = $rows->where('status', 'skipped')->pluck('user_id')->flip();
        $answeredIds = $submitted->pluck('user_id')->flip();

        $questions = [];
        foreach (self::QUESTIONS as $key => $label) {
            $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
            foreach ($submitted as $r) {
                if (isset($dist[(int) $r->{$key}])) {
                    $dist[(int) $r->{$key}]++;
                }
            }
            $questions[] = [
                'key' => $key,
                'label' => $label,
                'average' => $submitted->isEmpty() ? null : round($submitted->avg($key), 2),
                'distribution' => $dist,
            ];
        }

        $items = $submitted->map(function ($r) {
            $u = $r->user;
            $scores = array_map(fn ($k) => (int) $r->{$k}, array_keys(self::QUESTIONS));

            return [
                'id' => $r->id,
                'user' => $u ? [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'profile_pic' => $u->profile_pic,
                    'role' => $this->roleLabel($u),
                    'removed' => $u->deleted_at !== null,
                ] : null,
                'overall' => (int) $r->overall,
                'satisfaction' => (int) $r->satisfaction,
                'communication' => (int) $r->communication,
                'response' => (int) $r->response,
                'resolution' => (int) $r->resolution,
                'average' => round(array_sum($scores) / count($scores), 2),
                'comment' => $r->comment,
                'submitted_at' => $r->submitted_at?->toIso8601String(),
            ];
        })->values();

        $awaiting = $people->reject(fn ($u) => $answeredIds->has($u->id))
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'profile_pic' => $u->profile_pic,
                'role' => $this->roleLabel($u),
                'skipped' => $skippedIds->has($u->id),
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return response()->json([
            'supported' => true,
            'summary' => [
                'eligible' => $people->count(),
                'submitted' => $submitted->count(),
                'skipped' => $awaiting->where('skipped', true)->count(),
                'not_seen' => $awaiting->where('skipped', false)->count(),
                'average' => $items->isEmpty() ? null : round($items->avg('average'), 2),
                'comments' => $items->filter(fn ($i) => filled($i['comment']))->count(),
            ],
            'questions' => $questions,
            'items' => $items,
            'awaiting' => $awaiting,
        ]);
    }
}
