<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\API\admin\SummaryController;
use App\Http\Controllers\Controller;
use App\Models\EmployeeEvaluation;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WorkSession;
use App\Traits\CalculatesIdleTime;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GET /employee/home-stats
 *
 * Everything the home dashboard needs that the existing endpoints can't give
 * in one call:
 *
 *  - this month's hours leaderboard (company-wide, so a person can see where
 *    they stand),
 *  - who the signed-in user reports to and who reports to them, with each
 *    team member's latest weekly evaluation and month hours,
 *  - leave requests of the user's team (pending + upcoming approved),
 *  - the user's own recent weekly evaluations.
 *
 * Read-only and additive: every block degrades to empty rather than erroring
 * (e.g. before the employee_evaluations table exists), so the dashboard never
 * breaks because one part is unavailable.
 */
class HomeStatsController extends Controller
{
    use CalculatesIdleTime;

    /** Leaderboard is company-wide and identical for everyone — compute it once per this many seconds. */
    private const LEADERBOARD_TTL = 600;

    /** Employee types that are not evaluated (mirrors EmployeeEvaluationController). */
    private const NOT_EVALUATED_TYPES = ['Manager', 'Executive', 'Internee'];

    public function show()
    {
        $user = Auth::user();
        $today = Carbon::today();

        $leaderboard = $this->leaderboard($today);

        $myRank = null;
        $mySeconds = 0;
        foreach ($leaderboard['entries'] as $entry) {
            if ((int) $entry['user_id'] === (int) $user->id) {
                $myRank = $entry['rank'];
                $mySeconds = $entry['seconds'];
                break;
            }
        }

        $team = $this->team($user);
        $teamIds = $team->pluck('id')->all();

        return response()->json([
            'month' => [
                'label' => $today->format('F Y'),
                'from' => $today->copy()->startOfMonth()->toDateString(),
                'to' => $today->toDateString(),
            ],
            'leaderboard' => [
                'entries' => $leaderboard['entries'],
                'total' => count($leaderboard['entries']),
                'my_rank' => $myRank,
                'my_seconds' => $mySeconds,
            ],
            'manager' => $this->manager($user),
            'team' => $this->teamWithStats($team, $leaderboard['entries'], $today),
            'team_leaves' => $this->teamLeaves($teamIds, $today),
            'my_evaluations' => $this->myEvaluations($user),
            'evaluation_applies' => $this->isEvaluated($user),
        ]);
    }

    // ── leaderboard ───────────────────────────────────────────────────────

    /** @return array{entries: array<int, array>} */
    private function leaderboard(Carbon $today): array
    {
        $key = 'home-stats:leaderboard:' . $today->format('Y-m') . ':' . $today->toDateString();

        try {
            return Cache::remember($key, self::LEADERBOARD_TTL, fn () => $this->computeLeaderboard($today));
        } catch (\Throwable $e) {
            // Cache unavailable — compute directly rather than fail the page.
            return $this->computeLeaderboard($today);
        }
    }

    private function computeLeaderboard(Carbon $today): array
    {
        // Same people the company Summary ranks (test accounts, outsourced
        // staff and executives are left out), so the two never disagree.
        $excluded = app(SummaryController::class)->excludedUserIds();

        $people = User::where('user_role', 3)
            ->whereNotIn('id', $excluded)
            ->get(['id', 'name', 'profile_pic'])
            ->keyBy('id');

        if ($people->isEmpty()) {
            return ['entries' => []];
        }

        $from = $today->copy()->startOfMonth()->toDateString();
        $to = $today->toDateString();

        $seconds = [];
        $now = Carbon::now();

        // Chunk the user list so the IN() lists (sessions, then their idle
        // rows) stay a sensible size on a busy month.
        foreach ($people->keys()->chunk(150) as $idChunk) {
            $sessions = WorkSession::whereIn('user_id', $idChunk)
                ->whereBetween('start_date', [$from, $to])
                ->get(['id', 'user_id', 'start_date', 'start_time', 'end_date', 'end_time']);

            if ($sessions->isEmpty()) {
                continue;
            }

            $adjustments = collect();
            foreach ($sessions->pluck('id')->chunk(2000) as $sessionIds) {
                $adjustments = $adjustments->concat(
                    DB::table('session_time_adjustments')
                        ->whereIn('session_id', $sessionIds)
                        ->get(['session_id', 'start_time', 'end_time'])
                );
            }
            $adjustments = $adjustments->groupBy('session_id');

            foreach ($sessions as $session) {
                try {
                    $start = Carbon::parse($session->start_date . ' ' . $session->start_time);
                } catch (\Throwable $e) {
                    continue;
                }

                if (empty($session->end_time)) {
                    // A still-running session counts if it began today; an old
                    // unclosed one would otherwise add days of phantom time.
                    if ($start->toDateString() !== $to) {
                        continue;
                    }
                    $end = $now;
                } else {
                    try {
                        $end = Carbon::parse(($session->end_date ?: $session->start_date) . ' ' . $session->end_time);
                    } catch (\Throwable $e) {
                        continue;
                    }
                }

                $seconds[$session->user_id] = ($seconds[$session->user_id] ?? 0)
                    + $this->sessionWorkedSeconds($adjustments->get($session->id, collect()), $start, $end);
            }
        }

        $rows = [];
        foreach ($seconds as $userId => $secs) {
            if ($secs <= 0) {
                continue;
            }
            $person = $people->get($userId);
            if (!$person) {
                continue;
            }
            $rows[] = [
                'user_id' => (int) $userId,
                'name' => $person->name,
                'profile_pic' => $person->profile_pic,
                'seconds' => (int) $secs,
            ];
        }

        usort($rows, fn ($a, $b) => $b['seconds'] <=> $a['seconds'] ?: strcasecmp((string) $a['name'], (string) $b['name']));

        foreach ($rows as $i => &$row) {
            $row['rank'] = $i + 1;
        }
        unset($row);

        return ['entries' => $rows];
    }

    // ── people ────────────────────────────────────────────────────────────

    private function manager($user): ?array
    {
        $managerId = $user->employee_type === 'Internee' && $user->internee_manager_id
            ? $user->internee_manager_id
            : $user->manager_id;

        if (!$managerId) {
            return null;
        }

        $m = User::find($managerId, ['id', 'name', 'profile_pic', 'employee_type']);

        return $m ? [
            'id' => $m->id,
            'name' => $m->name,
            'profile_pic' => $m->profile_pic,
            'employee_type' => $m->employee_type,
        ] : null;
    }

    /** People who report to the user — as their reporting manager, or their internee mentor. */
    private function team($user)
    {
        return User::where('user_role', 3)
            ->where(function ($q) use ($user) {
                $q->where('manager_id', $user->id)
                    ->orWhere('internee_manager_id', $user->id);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'profile_pic', 'employee_type', 'manager_id', 'internee_manager_id']);
    }

    private function isEvaluated($user): bool
    {
        return (int) $user->user_role === 3
            && !in_array($user->employee_type, self::NOT_EVALUATED_TYPES, true);
    }

    private function teamWithStats($team, array $leaderboard, Carbon $today): array
    {
        if ($team->isEmpty()) {
            return [];
        }

        $monthSeconds = collect($leaderboard)->pluck('seconds', 'user_id');

        // Latest evaluated week per team member. A person can be rated by
        // several project managers in one week; that week's score is the average.
        $latest = [];
        if ($this->evaluationsReady()) {
            $byPerson = EmployeeEvaluation::whereIn('employee_id', $team->pluck('id'))
                ->orderByDesc('week_start')
                ->orderByDesc('id')
                ->get()
                ->groupBy('employee_id');

            foreach ($byPerson as $employeeId => $rows) {
                $newestWeek = $rows->first()->week_start->toDateString();
                $sameWeek = $rows->filter(fn ($e) => $e->week_start->toDateString() === $newestWeek);

                $latest[$employeeId] = [
                    'overall_percent' => round($sameWeek->avg('overall_percent'), 1),
                    'week_start' => $newestWeek,
                    'week_end' => $sameWeek->first()->week_end,
                ];
            }
        }

        $onLeave = LeaveRequest::whereIn('user_id', $team->pluck('id'))
            ->where('status', 'Approved')
            ->whereDate('start_date', '<=', $today->toDateString())
            ->whereDate('end_date', '>=', $today->toDateString())
            ->pluck('user_id')
            ->flip();

        return $team->map(function ($m) use ($monthSeconds, $latest, $onLeave) {
            return [
                'id' => $m->id,
                'name' => $m->name,
                'profile_pic' => $m->profile_pic,
                'employee_type' => $m->employee_type,
                'evaluated' => !in_array($m->employee_type, self::NOT_EVALUATED_TYPES, true),
                'month_seconds' => (int) ($monthSeconds[$m->id] ?? 0),
                'on_leave_today' => isset($onLeave[$m->id]),
                'latest_evaluation' => $latest[$m->id] ?? null,
            ];
        })->values()->all();
    }

    private function teamLeaves(array $teamIds, Carbon $today): array
    {
        if (empty($teamIds)) {
            return [];
        }

        $rows = LeaveRequest::with('user:id,name,profile_pic')
            ->whereIn('user_id', $teamIds)
            ->where(function ($q) use ($today) {
                $q->where('status', 'Pending')
                    ->orWhere(function ($q2) use ($today) {
                        $q2->where('status', 'Approved')
                            ->whereDate('end_date', '>=', $today->toDateString());
                    });
            })
            ->orderByRaw("CASE WHEN status = 'Pending' THEN 0 ELSE 1 END")
            ->orderBy('start_date')
            ->limit(30)
            ->get();

        return $rows->map(fn ($l) => [
            'id' => $l->id,
            'user_id' => $l->user_id,
            'name' => $l->user->name ?? null,
            'profile_pic' => $l->user->profile_pic ?? null,
            'leave_type' => $l->leave_type,
            'start_date' => Carbon::parse($l->start_date)->toDateString(),
            'end_date' => Carbon::parse($l->end_date)->toDateString(),
            'status' => $l->status,
        ])->all();
    }

    // ── evaluations ───────────────────────────────────────────────────────

    private function evaluationsReady(): bool
    {
        static $ready = null;

        if ($ready === null) {
            try {
                $ready = Schema::hasTable('employee_evaluations');
            } catch (\Throwable $e) {
                $ready = false;
            }
        }

        return $ready;
    }

    /**
     * The signed-in user's own weekly evaluations, newest week first. When
     * several project managers rated the same week the score is their average
     * (names kept so the dashboard can say who).
     */
    private function myEvaluations($user): array
    {
        if (!$this->evaluationsReady()) {
            return [];
        }

        return EmployeeEvaluation::with('manager:id,name')
            ->where('employee_id', $user->id)
            ->orderByDesc('week_start')
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->groupBy(fn ($e) => $e->week_start->toDateString())
            ->take(8)
            ->map(fn ($rows, $week) => [
                'week_start' => $week,
                'week_end' => $rows->first()->week_end,
                'overall_percent' => round($rows->avg('overall_percent'), 1),
                'manager_name' => $rows->map(fn ($e) => $e->manager->name ?? null)->filter()->unique()->implode(', ') ?: null,
            ])
            ->values()
            ->all();
    }
}
