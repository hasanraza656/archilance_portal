<?php

namespace App\Http\Controllers\API\admin;

use App\Http\Controllers\Controller;
use App\Models\InterneeRating;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Admin's read-only window onto internee grading.
 *
 * The employee endpoints only show a manager their own interns and ratings, and they
 * hide anyone who has been soft-deleted. An admin needs the whole record, including
 * interns and graders who have since left, so these two endpoints:
 *   - list EVERY internee (soft-deleted ones included, flagged with `deleted_at`)
 *   - list EVERY rating, resolving the intern / grader even when they are soft-deleted
 * Nothing here writes.
 */
class InterneeGradingAdminController extends Controller
{
    /** GET /admin/my-internees — every internee in the system, removed ones included. */
    public function internees()
    {
        $internees = User::withTrashed()
            ->where('employee_type', 'Internee')
            ->orderByRaw('deleted_at IS NOT NULL')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'profile_pic', 'internee_manager_id', 'joining_date', 'deleted_at']);

        return response()->json(['data' => $internees]);
    }

    /**
     * GET /admin/internee-grading/summary
     * One row per internee (removed ones included) with their overall average across EVERY
     * grade. Same maths as the grading screens: a "did not work" or all-zero day counts as 0,
     * so the average is the honest figure, not a flattering one. Optional date_from / date_to.
     */
    public function summary(Request $request)
    {
        $internees = User::withTrashed()
            ->where('employee_type', 'Internee')
            ->get(['id', 'name', 'email', 'profile_pic', 'internee_manager_id', 'joining_date', 'deleted_at']);

        $ratings = InterneeRating::query()
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('rating_date', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('rating_date', '<=', $request->date_to))
            ->get([
                'internee_id', 'manager_id', 'rating_date', 'did_not_work',
                'technical_accuracy', 'learning_improvement', 'ownership_initiative',
                'communication_professionalism', 'overall_recommendation',
            ])
            ->groupBy('internee_id');

        $managerIds = $internees->pluck('internee_manager_id')
            ->merge($ratings->flatten(1)->pluck('manager_id'))
            ->filter()->unique()->values();
        $managers = User::withTrashed()->whereIn('id', $managerIds)->get(['id', 'name', 'deleted_at'])->keyBy('id');

        $rows = $internees->map(function ($u) use ($ratings, $managers) {
            $mine = $ratings->get($u->id, collect());

            $scores = $mine->map(function ($r) {
                if ($r->did_not_work) {
                    return 0.0;
                }
                $vals = collect([
                    $r->technical_accuracy, $r->learning_improvement, $r->ownership_initiative,
                    $r->communication_professionalism, $r->overall_recommendation,
                ])->filter(fn ($v) => $v !== null);

                return $vals->isEmpty() ? 0.0 : $vals->sum() / $vals->count();
            });

            $current = $u->internee_manager_id ? $managers->get($u->internee_manager_id) : null;
            $last = $mine->max(fn ($r) => $r->rating_date?->toDateString());

            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'profile_pic' => $u->profile_pic,
                'joining_date' => $u->joining_date,
                'deleted_at' => $u->deleted_at,
                'manager' => $current ? ['id' => $current->id, 'name' => $current->name, 'deleted_at' => $current->deleted_at] : null,
                'grades' => $mine->count(),
                'average' => $scores->isEmpty() ? null : round($scores->avg(), 2),
                'zero_days' => $scores->filter(fn ($v) => $v == 0)->count(),
                'first_graded' => $mine->min(fn ($r) => $r->rating_date?->toDateString()),
                'last_graded' => $last,
            ];
        })
            // Interns who have grades first (best average first), then the rest, removed ones last.
            ->sortBy([
                fn ($a, $b) => ($a['deleted_at'] !== null) <=> ($b['deleted_at'] !== null),
                fn ($a, $b) => ($b['grades'] > 0) <=> ($a['grades'] > 0),
                fn ($a, $b) => ($b['average'] ?? -1) <=> ($a['average'] ?? -1),
                fn ($a, $b) => strcasecmp($a['name'], $b['name']),
            ])
            ->values();

        return response()->json(['data' => $rows]);
    }

    /**
     * GET /admin/internee-rating
     * Filters: internee_id, manager_id, task_id, project_id, date_from, date_to
     */
    public function index(Request $request)
    {
        $withTrashedUser = fn ($q) => $q->withTrashed()->select('id', 'name', 'email', 'profile_pic', 'deleted_at');

        $query = InterneeRating::with([
            'manager' => $withTrashedUser,
            'internee' => $withTrashedUser,
            'task:id,task_title,project_id',
            'task.project:id,project_name',
        ]);

        if ($request->filled('internee_id')) {
            $query->where('internee_id', $request->internee_id);
        }
        if ($request->filled('manager_id')) {
            $query->where('manager_id', $request->manager_id);
        }
        if ($request->filled('task_id')) {
            $query->where('task_id', $request->task_id);
        }
        if ($request->filled('project_id')) {
            $query->whereHas('task', fn ($q) => $q->where('project_id', $request->project_id));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('rating_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('rating_date', '<=', $request->date_to);
        }

        $payload = $query->orderBy('rating_date', 'desc')->orderBy('id', 'desc')->paginate(20)->toArray();
        $payload['eligibility'] = null;

        return response()->json($payload);
    }
}
