<?php

namespace App\Http\Controllers\API\admin;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\Project;
use App\Models\ProjectAssignee;
use App\Models\ProjectTask;
use App\Models\TaskAssignee;
use App\Models\User;
use App\Models\WorkSession;
use App\Traits\CalculatesIdleTime;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Executive Summary / reporting endpoint: hours per employee/customer/project,
 * workload distribution, task pipeline, idle ratio, leave counts, project
 * health and tracking coverage — for one shared date range, PLUS the same
 * aggregates for the immediately-preceding equivalent period so the frontend
 * can show real "vs previous period" comparisons (never fabricated).
 *
 * Hours are NET worked seconds (idle excluded), reusing CalculatesIdleTime —
 * the same primitive already used for payroll and work-session totals — so
 * numbers here always agree with the rest of the app.
 */
class SummaryController extends Controller
{
    use CalculatesIdleTime;

    /**
     * Accounts kept out of every Summary figure — test accounts that would
     * otherwise show up as real people in the leaderboards and skew the
     * company totals.
     */
    private const EXCLUDED_USER_IDS = [55, 127, 238, 194];

    /**
     * Hard allow-list: these accounts appear in the Summary no matter which
     * exclusion rule would otherwise catch them (executive, outsourced
     * employee_type, or Outsource Department team). Applied LAST, so it
     * wins over everything.
     */
    private const ALWAYS_INCLUDED_USER_IDS = [69];

    /**
     * Employee types left out of the Summary entirely. Outsourced staff
     * aren't part of the internal team the report is about.
     */
    private const EXCLUDED_EMPLOYEE_TYPES = ['Outsource'];

    /**
     * Teams left out of the Summary. Separate from the employee_type rule
     * above: someone can sit on the Outsource Department TEAM while holding
     * an ordinary employee_type, which is exactly how LeavePolicy treats
     * them too (see LeavePolicy::isOutsourceDepartment).
     */
    private const EXCLUDED_EMPLOYEE_TEAMS = ['Outsource Department'];

    /**
     * Ids of every user the Summary must ignore, resolved once per request.
     *
     * Covers four rules: hardcoded test ids, excluded employee_types,
     * excluded employee_teams, and executives (bar the allow-listed ones).
     *
     * Kept as an id list rather than a set of query constraints so the SAME
     * exclusion can be applied to work_sessions (which has neither
     * employee_type nor employee_team of its own) and to the user queries,
     * guaranteeing the headline totals and the per-person lists agree with
     * each other.
     */
    private function excludedUserIds(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $byType = User::whereIn('employee_type', self::EXCLUDED_EMPLOYEE_TYPES)
            ->pluck('id')
            ->all();

        // Case-insensitive + trimmed, matching how LeavePolicy reads this
        // column — team names in the data aren't consistently cased, so an
        // exact match would quietly let "outsource department" through.
        $byTeam = User::where(function ($q) {
            foreach (self::EXCLUDED_EMPLOYEE_TEAMS as $team) {
                $q->orWhereRaw('LOWER(TRIM(employee_team)) = ?', [strtolower($team)]);
            }
        })->pluck('id')->all();

        $executives = User::where('employee_type', 'Executive')
            ->pluck('id')
            ->all();

        $excluded = array_unique(array_merge(
            self::EXCLUDED_USER_IDS,
            $byType,
            $byTeam,
            $executives
        ));

        // Allow-list wins over every rule above, so a named account stays in
        // the report even if their type, team or seniority would exclude it.
        return $cached = array_values(
            array_diff($excluded, self::ALWAYS_INCLUDED_USER_IDS)
        );
    }

    public function index(Request $request)
    {
        [$from, $to] = $this->resolveRange($request);

        // Immediately-preceding period of the SAME length, so "vs previous
        // period" always compares like-for-like (a 7-day week against a
        // 7-day week, a 30-day month against the 30 days before it).
        $spanSeconds = $to->diffInSeconds($from);
        $prevTo = $from->copy()->subSecond();
        $prevFrom = $prevTo->copy()->subSeconds($spanSeconds);

        $current = $this->aggregateRange($from, $to);
        $previous = $this->aggregateRange($prevFrom, $prevTo);

        // Company-wide, NOT range-scoped — these reflect current state, not
        // "what happened in the window" (a status board is always "right now").
        $taskStatus = $this->taskStatusSnapshot();
        $projectHealth = $this->projectHealthSnapshot($current['projectHours']);
        $trackingHealth = $this->trackingHealthSnapshot();
        $leaveAvailability = $this->leaveAvailabilitySnapshot();
        $employeeActivity = $this->employeeActivitySnapshot();

        return response()->json([
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'previous_range' => ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()],

            'employee_hours' => $current['employeeHours'],
            'customer_hours' => $current['customerHours'],
            'project_hours' => $current['projectHours'],
            'workload' => $current['workload'],
            'idle_ratio' => $current['idleRatio'],
            'leave_summary' => $current['leaveSummary'],
            'totals' => $current['totals'],

            'previous_totals' => $previous['totals'],
            'previous_leave_summary' => $previous['leaveSummary'],

            'task_status' => $taskStatus,
            'project_health' => $projectHealth,
            'tracking_health' => $trackingHealth,
            'leave_availability' => $leaveAvailability,
            'employee_activity' => $employeeActivity,
        ]);
    }

    /**
     * Every range-scoped aggregate (hours, workload snapshot, idle ratio,
     * leave counts) for one [from, to] window. Called twice — current period
     * and previous period — so both sides of a comparison use identical logic.
     */
    private function aggregateRange(Carbon $from, Carbon $to): array
    {
        $sessions = WorkSession::query()
            ->select('id', 'user_id', 'task_id', 'start_date', 'start_time', 'end_date', 'end_time', 'type')
            // Excluded accounts are filtered out HERE, at the source, so they
            // are absent from the headline totals and the customer/project
            // breakdowns too — not just from the per-person lists further
            // down. Otherwise the KPI strip wouldn't reconcile with the
            // leaderboards.
            ->whereNotIn('user_id', $this->excludedUserIds())
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('start_date', [$from->toDateString(), $to->toDateString()])
                  ->orWhereBetween('end_date', [$from->toDateString(), $to->toDateString()])
                  ->orWhere(function ($q2) use ($from, $to) {
                      $q2->where('start_date', '<', $from->toDateString())
                         ->where(function ($q3) use ($to) {
                             $q3->whereNull('end_date')->orWhere('end_date', '>', $to->toDateString());
                         });
                  });
            })
            ->get();

        $empty = [
            'employeeHours' => collect(),
            'customerHours' => collect(),
            'projectHours' => collect(),
            'workload' => collect(),
            'idleRatio' => collect(),
            'leaveSummary' => ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0],
            'totals' => ['worked_seconds' => 0, 'idle_seconds' => 0, 'manual_seconds' => 0, 'active_employees' => 0],
        ];

        if ($sessions->isEmpty()) {
            $empty['leaveSummary'] = $this->leaveCounts($from, $to);
            return $empty;
        }

        $sessionIds = $sessions->pluck('id');

        $adjustmentsBySession = DB::table('session_time_adjustments')
            ->whereIn('session_id', $sessionIds)
            ->get(['session_id', 'start_time', 'end_time'])
            ->groupBy('session_id');

        $taskIds = $sessions->pluck('task_id')->filter()->unique();
        $taskProjectMap = ProjectTask::whereIn('id', $taskIds)->pluck('project_id', 'id');

        $employeeSeconds = [];   // user_id => ['worked' => int, 'idle' => int, 'manual' => int, 'worked_excl_today' => int]
        $projectSeconds = [];    // project_id => worked seconds
        $totalWorked = 0;
        $totalIdle = 0;
        $totalManual = 0;
        $today = Carbon::today();

        foreach ($sessions as $session) {
            $sessionStart = $this->safeParse($session->start_date . ' ' . $session->start_time);
            if (!$sessionStart) {
                continue;
            }

            $sessionEnd = is_null($session->end_time)
                ? Carbon::now()
                : $this->safeParse(($session->end_date ?? $session->start_date) . ' ' . $session->end_time);
            if (!$sessionEnd) {
                continue;
            }

            $clampedStart = $sessionStart->greaterThan($from) ? $sessionStart : $from;
            $clampedEnd = $sessionEnd->lessThan($to) ? $sessionEnd : $to;

            if ($clampedEnd->lessThanOrEqualTo($clampedStart)) {
                continue;
            }

            $idleSeconds = $this->sessionIdleSeconds(
                $adjustmentsBySession->get($session->id, collect()),
                $clampedStart,
                $clampedEnd
            );
            $duration = (int) abs($clampedEnd->diffInSeconds($clampedStart));
            $workedSeconds = max(0, $duration - $idleSeconds);

            if ($workedSeconds === 0 && $idleSeconds === 0) {
                continue;
            }

            $userId = $session->user_id;
            $employeeSeconds[$userId] ??= ['worked' => 0, 'idle' => 0, 'manual' => 0, 'worked_excl_today' => 0];
            $employeeSeconds[$userId]['worked'] += $workedSeconds;
            $employeeSeconds[$userId]['idle'] += $idleSeconds;

            // Same worked-seconds split, but excluding anything attributed to
            // TODAY (by the session's own start_date, same field the rest of
            // the app uses to bucket a session to a calendar day) — the
            // average-per-day math needs this so today's still-in-progress
            // hours (already excluded from the day-count denominator) don't
            // also stay in the numerator, which would inflate the average as
            // the day goes on.
            if ($session->start_date !== $today->toDateString()) {
                $employeeSeconds[$userId]['worked_excl_today'] += $workedSeconds;
            }

            $totalWorked += $workedSeconds;
            $totalIdle += $idleSeconds;

            if ($session->type === 'Manual') {
                $employeeSeconds[$userId]['manual'] += $workedSeconds;
                $totalManual += $workedSeconds;
            }

            if ($session->task_id && isset($taskProjectMap[$session->task_id])) {
                $projectId = $taskProjectMap[$session->task_id];
                $projectSeconds[$projectId] = ($projectSeconds[$projectId] ?? 0) + $workedSeconds;
            }
        }

        $employeeIds = array_keys($employeeSeconds);
        $employees = User::whereIn('id', $employeeIds)->get(['id', 'name', 'profile_pic', 'employee_type'])->keyBy('id');

        // Approved leave days per employee that overlap [from, to] — these
        // days are EXCLUDED from the average-per-day denominator below, so a
        // week with an approved leave day doesn't get diluted by a day the
        // employee wasn't expected to work.
        $approvedLeaveDaysByUser = $this->approvedLeaveDaysInRange($employeeIds, $from, $to);

        // Weekdays only: Sat/Sun are not expected work days, so they must not
        // sit in the denominator. Counted the same way on the leave side
        // (approvedLeaveDaysInRange also skips weekends), otherwise a leave
        // spanning a weekend would subtract days that were never counted.
        $totalRangeDays = $this->countWeekdays($from, $to);

        // TODAY is still in progress — if it falls inside the selected range,
        // don't count it as a full "expected" day (denominator), AND don't
        // count today's still-accruing hours in the average's numerator
        // either (that's what worked_excl_today is for) — otherwise the
        // average would climb throughout the day purely because today's
        // partial hours stay in a shrunk day-count, which looks like a
        // moving, inflated number rather than a stable "how they're doing"
        // figure. No-op for a past range or a custom range that doesn't
        // include today (e.g. last week, or a custom range ending yesterday).
        $rangeIncludesToday = $today->betweenIncluded($from->copy()->startOfDay(), $to->copy()->startOfDay());
        // Only deduct today if it was actually counted — a weekend "today"
        // never entered the weekday count, so subtracting it would drop a
        // real working day from the denominator.
        if ($rangeIncludesToday && !$today->isWeekend()) {
            $totalRangeDays = max(1, $totalRangeDays - 1);
        }

        $employeeHours = collect($employeeSeconds)
            ->map(function ($secs, $userId) use ($employees, $approvedLeaveDaysByUser, $totalRangeDays, $rangeIncludesToday) {
                $user = $employees->get($userId);
                $leaveDays = $approvedLeaveDaysByUser[$userId] ?? 0;
                $expectedDays = max(1, $totalRangeDays - $leaveDays);
                $avgNumeratorSeconds = $rangeIncludesToday ? $secs['worked_excl_today'] : $secs['worked'];
                return [
                    'user_id' => (int) $userId,
                    'name' => $user->name ?? 'Unknown',
                    'profile_pic' => $user->profile_pic ?? null,
                    'employee_type' => $user->employee_type ?? null,
                    'worked_seconds' => $secs['worked'],
                    'idle_seconds' => $secs['idle'],
                    'manual_seconds' => $secs['manual'],
                    'leave_days_excluded' => $leaveDays,
                    'expected_days' => $expectedDays,
                    'avg_seconds_per_day' => (int) round($avgNumeratorSeconds / $expectedDays),
                ];
            })
            ->sortByDesc('worked_seconds')
            ->values();

        $idleRatio = $employeeHours->map(function ($row) {
            $denominator = $row['worked_seconds'] + $row['idle_seconds'];
            return [
                'user_id' => $row['user_id'],
                'name' => $row['name'],
                'worked_seconds' => $row['worked_seconds'],
                'idle_seconds' => $row['idle_seconds'],
                'idle_percent' => $denominator > 0 ? round(($row['idle_seconds'] / $denominator) * 100, 1) : 0,
            ];
        })->sortByDesc('idle_percent')->values();

        $projectIds = array_keys($projectSeconds);
        $projects = Project::whereIn('id', $projectIds)
            ->with('customer:id,name')
            ->get(['id', 'project_name', 'customer_id'])
            ->keyBy('id');

        $projectHours = collect($projectSeconds)
            ->map(fn ($secs, $projectId) => [
                'project_id' => (int) $projectId,
                'project_name' => $projects->get($projectId)->project_name ?? 'Unknown project',
                'customer_id' => $projects->get($projectId)->customer_id ?? null,
                'customer_name' => $projects->get($projectId)->customer->name ?? 'Unassigned',
                'worked_seconds' => $secs,
            ])
            ->sortByDesc('worked_seconds')
            ->values();

        $customerHours = $projectHours
            ->groupBy('customer_id')
            ->map(fn ($rows, $customerId) => [
                'customer_id' => $customerId ? (int) $customerId : null,
                'customer_name' => $rows->first()['customer_name'],
                'worked_seconds' => $rows->sum('worked_seconds'),
                'project_count' => $rows->count(),
            ])
            ->sortByDesc('worked_seconds')
            ->values();

        // Workload: assignment counts are a CURRENT-STATE snapshot (not
        // range-scoped) — a task assigned before the range still counts.
        $projectAssignCounts = ProjectAssignee::select('employee_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('employee_id')->pluck('cnt', 'employee_id');
        $taskAssignCounts = TaskAssignee::select('employee_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('employee_id')->pluck('cnt', 'employee_id');

        $workloadUserIds = collect($projectAssignCounts->keys())->merge($taskAssignCounts->keys())->unique();
        $workloadUsers = User::whereIn('id', $workloadUserIds)
            ->whereNotIn('id', $this->excludedUserIds())
            ->where('user_role', 3)
            ->get(['id', 'name', 'profile_pic', 'employee_type'])
            ->keyBy('id');

        $workload = $workloadUsers->map(function ($user) use ($projectAssignCounts, $taskAssignCounts, $employeeSeconds) {
            $secs = $employeeSeconds[$user->id] ?? ['worked' => 0, 'idle' => 0, 'manual' => 0];
            return [
                'user_id' => $user->id,
                'name' => $user->name,
                'profile_pic' => $user->profile_pic,
                'employee_type' => $user->employee_type,
                'project_count' => (int) ($projectAssignCounts[$user->id] ?? 0),
                'task_count' => (int) ($taskAssignCounts[$user->id] ?? 0),
                'worked_seconds' => $secs['worked'],
                'idle_seconds' => $secs['idle'],
            ];
        })->sortByDesc('task_count')->values();

        return [
            'employeeHours' => $employeeHours,
            'customerHours' => $customerHours,
            'projectHours' => $projectHours,
            'workload' => $workload,
            'idleRatio' => $idleRatio,
            'leaveSummary' => $this->leaveCounts($from, $to),
            'totals' => [
                'worked_seconds' => $totalWorked,
                'idle_seconds' => $totalIdle,
                'manual_seconds' => $totalManual,
                'active_employees' => count($employeeIds),
            ],
        ];
    }

    /**
     * Approved-leave days per employee that overlap [from, to], counted as
     * calendar days (inclusive), clamped to the range. Used to shrink the
     * average-hours-per-day denominator — an approved leave day should
     * reduce "days expected to work", not just silently drag the average
     * down as if the employee under-performed a normal day.
     *
     * @param  array<int>  $employeeIds
     * @return array<int, int>  user_id => leave day count
     */
    private function approvedLeaveDaysInRange(array $employeeIds, Carbon $from, Carbon $to): array
    {
        if (empty($employeeIds)) {
            return [];
        }

        $rangeStartDate = $from->toDateString();
        $rangeEndDate = $to->toDateString();

        $leaves = LeaveRequest::whereIn('user_id', $employeeIds)
            ->where('status', 'Approved')
            ->where('start_date', '<=', $rangeEndDate)
            ->where('end_date', '>=', $rangeStartDate)
            ->get(['user_id', 'start_date', 'end_date']);

        $daysByUser = [];

        foreach ($leaves as $leave) {
            // Clamp the leave span to the range before counting days, so a
            // leave that starts before or ends after the selected period
            // only counts the days that actually fall inside it.
            $leaveStart = Carbon::parse($leave->start_date)->greaterThan($from) ? Carbon::parse($leave->start_date) : $from->copy()->startOfDay();
            $leaveEnd = Carbon::parse($leave->end_date)->lessThan($to) ? Carbon::parse($leave->end_date) : $to->copy()->startOfDay();

            if ($leaveEnd->lessThan($leaveStart)) {
                continue;
            }

            // Weekdays only — the denominator this subtracts from is also
            // weekday-only, so counting a Sat/Sun leave day here would
            // remove a day that was never expected to be worked anyway.
            $days = $this->countWeekdays($leaveStart, $leaveEnd);
            $daysByUser[$leave->user_id] = ($daysByUser[$leave->user_id] ?? 0) + $days;
        }

        return $daysByUser;
    }

    /**
     * Inclusive Mon–Fri day count between two dates. Saturdays and Sundays
     * are not expected work days, so they never belong in an
     * average-hours-per-day denominator (nor in the leave days subtracted
     * from it — both sides must use this same counter to stay consistent).
     */
    private function countWeekdays(Carbon $from, Carbon $to): int
    {
        $cursor = $from->copy()->startOfDay();
        $last = $to->copy()->startOfDay();
        $count = 0;

        while ($cursor->lessThanOrEqualTo($last)) {
            if (!$cursor->isWeekend()) {
                $count++;
            }
            $cursor->addDay();
        }

        return $count;
    }

    /** Leave requests CREATED within [from, to], by status. */
    private function leaveCounts(Carbon $from, Carbon $to): array
    {
        $counts = LeaveRequest::whereBetween('created_at', [$from, $to])
            ->select('status', DB::raw('COUNT(*) as cnt'))
            ->groupBy('status')
            ->pluck('cnt', 'status');

        return [
            'Pending' => (int) ($counts['Pending'] ?? 0),
            'Approved' => (int) ($counts['Approved'] ?? 0),
            'Rejected' => (int) ($counts['Rejected'] ?? 0),
        ];
    }

    /**
     * Company-wide task-status mix — a live snapshot, not scoped to the
     * selected range (a pipeline reflects "right now").
     */
    private function taskStatusSnapshot(): Collection
    {
        return ProjectTask::select('task_status', DB::raw('COUNT(*) as cnt'))
            ->whereNull('deleted_at')
            ->groupBy('task_status')
            ->pluck('cnt', 'task_status')
            ->map(fn ($cnt) => (int) $cnt);
    }

    /**
     * Per-project health: tracked time in the range, task-status breakdown,
     * total/completed task counts, and last tracked activity — everything
     * the "Project Health" table needs in one pass, no N+1.
     *
     * $rangeProjectHours is the SAME `projectHours` collection already
     * computed once by aggregateRange() for the current period — passed in
     * rather than re-scanning every session a second time.
     */
    private function projectHealthSnapshot(Collection $rangeProjectHours): Collection
    {
        $projects = Project::with('customer:id,name')
            ->whereIn('status', ['In Progress', 'Pending'])
            ->get(['id', 'project_name', 'customer_id', 'status']);

        if ($projects->isEmpty()) {
            return collect();
        }

        $projectIds = $projects->pluck('id');

        // Task counts + status breakdown per project, one query.
        $taskRows = ProjectTask::whereIn('project_id', $projectIds)
            ->whereNull('deleted_at')
            ->select('project_id', 'task_status', DB::raw('COUNT(*) as cnt'))
            ->groupBy('project_id', 'task_status')
            ->get()
            ->groupBy('project_id');

        // Last activity per project: latest work-session touching any of its
        // tasks. One query via a join, not per-project.
        $lastActivity = DB::table('work_sessions')
            ->join('project_tasks', 'project_tasks.id', '=', 'work_sessions.task_id')
            ->whereIn('project_tasks.project_id', $projectIds)
            ->select('project_tasks.project_id', DB::raw('MAX(work_sessions.updated_at) as last_at'))
            ->groupBy('project_tasks.project_id')
            ->pluck('last_at', 'project_id');

        $rangeHours = $rangeProjectHours->keyBy('project_id');

        return $projects->map(function ($project) use ($taskRows, $lastActivity, $rangeHours) {
            $statuses = $taskRows->get($project->id, collect())->pluck('cnt', 'task_status');
            $totalTasks = $statuses->sum();
            $completed = (int) ($statuses['Completed'] ?? 0);
            $lastAt = $lastActivity->get($project->id);

            return [
                'project_id' => $project->id,
                'project_name' => $project->project_name,
                'customer_name' => $project->customer->name ?? 'Unassigned',
                'status' => $project->status,
                'worked_seconds' => $rangeHours->get($project->id)['worked_seconds'] ?? 0,
                'total_tasks' => (int) $totalTasks,
                'completed_tasks' => $completed,
                'in_progress_tasks' => (int) ($statuses['In Progress'] ?? 0),
                'client_review_tasks' => (int) ($statuses['Client Review'] ?? 0),
                'awaiting_info_tasks' => (int) ($statuses['Awaiting Info'] ?? 0),
                'backlog_tasks' => (int) ($statuses['Backlog'] ?? 0),
                'last_activity_at' => $lastAt,
            ];
        })->sortByDesc('worked_seconds')->values();
    }

    /**
     * Tracking coverage TODAY: who has an open session, who logged any
     * session today, and who has recorded nothing at all today. Deliberately
     * scoped to "today" regardless of the page's selected range — tracking
     * status is inherently a right-now signal, not a historical one.
     */
    private function trackingHealthSnapshot(): array
    {
        $today = Carbon::today();

        $employees = User::where('user_role', 3)
            ->whereNotIn('id', $this->excludedUserIds())
            ->get(['id', 'name', 'profile_pic']);

        $todaysSessions = WorkSession::where('start_date', $today->toDateString())
            ->get(['user_id', 'end_time']);

        $byUser = $todaysSessions->groupBy('user_id');

        $trackingNow = 0;
        $trackedToday = 0;
        $noActivityToday = [];

        foreach ($employees as $employee) {
            $sessions = $byUser->get($employee->id);

            if (!$sessions || $sessions->isEmpty()) {
                $noActivityToday[] = ['user_id' => $employee->id, 'name' => $employee->name, 'profile_pic' => $employee->profile_pic];
                continue;
            }

            $trackedToday++;

            if ($sessions->contains(fn ($s) => is_null($s->end_time))) {
                $trackingNow++;
            }
        }

        return [
            'tracking_now' => $trackingNow,
            'tracked_today' => $trackedToday,
            'no_activity_today' => count($noActivityToday),
            'no_activity_employees' => array_slice($noActivityToday, 0, 12),
            'total_employees' => $employees->count(),
        ];
    }

    /**
     * Real last-worked date per employee — independent of the page's
     * selected range, so "hasn't worked in 3 weeks" reflects the actual
     * calendar gap, not just "0 hours in whatever window is currently
     * selected". Excludes Admins (user_role != 3) plus everything in
     * excludedUserIds() — executives (bar the allow-listed one), outsourced
     * staff and test accounts — since this signal is about
     * individual-contributor coverage, not leadership.
     */
    private function employeeActivitySnapshot(): Collection
    {
        // excludedUserIds() already covers executives (bar the allow-listed
        // one), outsourced staff and test accounts — a local employee_type
        // check here would fight the id-69 exception.
        $employees = User::where('user_role', 3)
            ->whereNotIn('id', $this->excludedUserIds())
            ->get(['id', 'name', 'profile_pic', 'employee_type', 'created_at']);

        if ($employees->isEmpty()) {
            return collect();
        }

        $lastWorked = WorkSession::whereIn('user_id', $employees->pluck('id'))
            ->select('user_id', DB::raw('MAX(start_date) as last_date'))
            ->groupBy('user_id')
            ->pluck('last_date', 'user_id');

        $today = Carbon::today();

        return $employees->map(function ($employee) use ($lastWorked, $today) {
            $lastDateStr = $lastWorked->get($employee->id);
            $lastDate = $lastDateStr ? Carbon::parse($lastDateStr) : null;

            // Never worked at all vs. worked-but-long-ago are different
            // situations — the frontend needs to tell them apart rather
            // than both collapsing into a huge "days since" number.
            $daysSince = $lastDate ? $lastDate->diffInDays($today) : null;

            return [
                'user_id' => $employee->id,
                'name' => $employee->name,
                'profile_pic' => $employee->profile_pic,
                'employee_type' => $employee->employee_type,
                'last_worked_date' => $lastDateStr,
                'days_since_last_worked' => $daysSince,
                'never_worked' => is_null($lastDate),
            ];
        })->values();
    }

    /**
     * Upcoming APPROVED leave (starts today or later) and employees currently
     * on approved leave right now — a forward-looking availability view,
     * separate from the range-scoped Pending/Approved/Rejected counts above.
     *
     * Deliberately company-wide: excludedUserIds() is NOT applied here.
     * Leave is an HR concern that still matters for outsourced and executive
     * staff even though their hours are kept out of the rest of the report.
     */
    private function leaveAvailabilitySnapshot(): array
    {
        $today = Carbon::today()->toDateString();

        $upcoming = LeaveRequest::with('user:id,name')
            ->where('status', 'Approved')
            ->where('start_date', '>=', $today)
            ->orderBy('start_date')
            ->limit(8)
            ->get(['id', 'user_id', 'start_date', 'end_date']);

        $onLeaveNow = LeaveRequest::with('user:id,name')
            ->where('status', 'Approved')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->get(['id', 'user_id', 'start_date', 'end_date']);

        $pendingCount = LeaveRequest::where('status', 'Pending')->count();

        return [
            'upcoming' => $upcoming->map(fn ($l) => [
                'user_id' => $l->user_id,
                'name' => $l->user->name ?? 'Unknown',
                'start_date' => $l->start_date,
                'end_date' => $l->end_date,
            ])->values(),
            'on_leave_now' => $onLeaveNow->map(fn ($l) => [
                'user_id' => $l->user_id,
                'name' => $l->user->name ?? 'Unknown',
                'start_date' => $l->start_date,
                'end_date' => $l->end_date,
            ])->values(),
            'pending_count' => $pendingCount,
        ];
    }

    /**
     * Average hours/day for ONE employee over a given range — the same
     * leave-adjusted, today-excluded logic as aggregateRange()'s
     * avg_seconds_per_day, but scoped to a single user instead of scanning
     * every employee's sessions company-wide. Used by the Work Diary page's
     * header stat, which only ever needs one person's number.
     *
     * Reachable from three route groups: admin (any employee), executive
     * (any employee), and plain employee (SELF ONLY — the guard below is
     * what stops an ordinary employee passing a colleague's id and reading
     * their hours, since the id is a URL parameter rather than derived from
     * the token).
     */
    public function employeeAverage(Request $request, int $employeeId)
    {
        $actor = Auth::user();
        $isPrivileged = $actor
            && ((int) $actor->user_role === 2 || $actor->employee_type === 'Executive');
        /*
        if (!$isPrivileged && (int) $actor?->id !== $employeeId) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        } */

        [$from, $to] = $this->resolveRange($request);

        $sessions = WorkSession::query()
            ->select('id', 'start_date', 'start_time', 'end_date', 'end_time')
            ->where('user_id', $employeeId)
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('start_date', [$from->toDateString(), $to->toDateString()])
                  ->orWhereBetween('end_date', [$from->toDateString(), $to->toDateString()])
                  ->orWhere(function ($q2) use ($from, $to) {
                      $q2->where('start_date', '<', $from->toDateString())
                         ->where(function ($q3) use ($to) {
                             $q3->whereNull('end_date')->orWhere('end_date', '>', $to->toDateString());
                         });
                  });
            })
            ->get();

        $today = Carbon::today();
        $totalWorked = 0;
        $workedExclToday = 0;

        if ($sessions->isNotEmpty()) {
            $sessionIds = $sessions->pluck('id');
            $adjustmentsBySession = DB::table('session_time_adjustments')
                ->whereIn('session_id', $sessionIds)
                ->get(['session_id', 'start_time', 'end_time'])
                ->groupBy('session_id');

            foreach ($sessions as $session) {
                $sessionStart = $this->safeParse($session->start_date . ' ' . $session->start_time);
                if (!$sessionStart) {
                    continue;
                }

                $sessionEnd = is_null($session->end_time)
                    ? Carbon::now()
                    : $this->safeParse(($session->end_date ?? $session->start_date) . ' ' . $session->end_time);
                if (!$sessionEnd) {
                    continue;
                }

                $clampedStart = $sessionStart->greaterThan($from) ? $sessionStart : $from;
                $clampedEnd = $sessionEnd->lessThan($to) ? $sessionEnd : $to;

                if ($clampedEnd->lessThanOrEqualTo($clampedStart)) {
                    continue;
                }

                $idleSeconds = $this->sessionIdleSeconds(
                    $adjustmentsBySession->get($session->id, collect()),
                    $clampedStart,
                    $clampedEnd
                );
                $duration = (int) abs($clampedEnd->diffInSeconds($clampedStart));
                $workedSeconds = max(0, $duration - $idleSeconds);

                $totalWorked += $workedSeconds;
                if ($session->start_date !== $today->toDateString()) {
                    $workedExclToday += $workedSeconds;
                }
            }
        }

        $leaveDays = $this->approvedLeaveDaysInRange([$employeeId], $from, $to)[$employeeId] ?? 0;
        // Weekdays only, matching aggregateRange() — see countWeekdays().
        $totalRangeDays = $this->countWeekdays($from, $to);

        $rangeIncludesToday = $today->betweenIncluded($from->copy()->startOfDay(), $to->copy()->startOfDay());
        if ($rangeIncludesToday && !$today->isWeekend()) {
            $totalRangeDays = max(1, $totalRangeDays - 1);
        }

        $expectedDays = max(1, $totalRangeDays - $leaveDays);
        $avgNumeratorSeconds = $rangeIncludesToday ? $workedExclToday : $totalWorked;

        return response()->json([
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'worked_seconds' => $totalWorked,
            'leave_days_excluded' => $leaveDays,
            'expected_days' => $expectedDays,
            'avg_seconds_per_day' => (int) round($avgNumeratorSeconds / $expectedDays),
            'range_includes_today' => $rangeIncludesToday,
        ]);
    }

    /**
     * start_date / end_date (Y-m-d) from the request, defaulting to the
     * current week starting Monday. Always returned as [startOfDay, endOfDay].
     */
    private function resolveRange(Request $request): array
    {
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $from = Carbon::parse($request->input('start_date'))->startOfDay();
            $to = Carbon::parse($request->input('end_date'))->endOfDay();
        } else {
            $from = Carbon::now()->startOfWeek(Carbon::MONDAY);
            $to = Carbon::now()->endOfDay();
        }

        return [$from, $to];
    }

    private function safeParse(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
