<?php

namespace App\Http\Controllers\API\customer;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Subscription;
use App\Models\WorkSession;
use App\Traits\CalculatesIdleTime;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The customer's own dashboard: how their jobs are going, in numbers and charts.
 *
 * What a customer sees here is deliberately limited to THEIR OWN jobs and to aggregates: hours, progress, deadlines,
 * what was delivered. It never names the employees who did the work and never touches screenshots, work windows or
 * activity data.
 *
 * Two endpoints, so the page paints immediately and the slower Stripe lookup fills in afterwards:
 *   GET /customer/dashboard/overview      jobs, tasks, hours, deadlines (cached 5 minutes)
 *   GET /customer/dashboard/subscription  Stripe subscription matched by the login email (cached 10 minutes)
 */
class CustomerDashboardController extends Controller
{
    use CalculatesIdleTime;

    private const DONE = ['completed', 'complete', 'done'];

    private function isDone(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), self::DONE, true);
    }

    /* ------------------------------------------------------------------ overview */

    public function overview(Request $request)
    {
        $user = Auth::user();
        // The version in the key means a change to what the overview contains is never served from an older cached copy.
        $key = 'customer-dashboard:overview:v2:' . $user->id;

        if ($request->boolean('fresh')) {
            Cache::forget($key);
        }

        $data = Cache::remember($key, now()->addMinutes(5), fn () => $this->buildOverview((int) $user->id));

        return response()->json($data);
    }

    /**
     * The dashboard speaks in PROJECTS and TASKS, not jobs:
     *   - a "project" is a parent-level task (no parent task) inside the customer's jobs;
     *   - a "task" is a piece of work, i.e. a task with no sub-tasks (a project with no sub-tasks counts as one task);
     *   - a project's progress is how many of the tasks under it are finished.
     * The job a project sits in is only kept as muted context (`job`).
     */
    private function buildOverview(int $customerId): array
    {
        $today = Carbon::today();
        $jobs = Project::where('customer_id', $customerId)->get(['id', 'project_name']);
        $jobName = $jobs->pluck('project_name', 'id');
        $jobIds = $jobs->pluck('id')->all();

        $all = $jobIds
            ? ProjectTask::whereIn('project_id', $jobIds)->get(['id', 'project_id', 'parent_task_id', 'task_title', 'task_status', 'due_date', 'completed_date', 'priority', 'updated_at'])
            : collect();
        $byId = $all->keyBy('id');

        // Root (parent-level) task of every task, and which tasks have children.
        $root = [];
        $hasChildren = [];
        foreach ($all as $t) {
            if ($t->parent_task_id && $byId->has($t->parent_task_id)) {
                $hasChildren[$t->parent_task_id] = true;
            }
        }
        $rootOf = function ($t) use ($byId, &$root) {
            $cur = $t;
            $guard = 0;
            while ($cur->parent_task_id && $byId->has($cur->parent_task_id) && $guard++ < 50) {
                $cur = $byId->get($cur->parent_task_id);
            }

            return $root[$t->id] = $cur->id;
        };
        foreach ($all as $t) {
            $rootOf($t);
        }

        $projects = $all->filter(fn ($t) => $root[$t->id] === $t->id)->values();           // parent-level tasks
        $tasks = $all->filter(fn ($t) => empty($hasChildren[$t->id]))->values();            // leaves = actual work items
        $title = fn ($id) => $byId->get($id)->task_title ?? 'Project';

        /* ------------------------------ hours (idle excluded), bucketed by project ------------------------------ */
        $hours = $this->hours($all->pluck('id')->all(), $root);

        /* ------------------------------ projects ------------------------------ */
        $tasksByRoot = $tasks->groupBy(fn ($t) => $root[$t->id]);
        $projectRows = $projects->map(function ($p) use ($tasksByRoot, $hours, $today, $jobName) {
            $leaves = $tasksByRoot->get($p->id, collect());
            $total = $leaves->count();
            $done = $leaves->filter(fn ($x) => $this->isDone($x->task_status))->count();
            $finished = $this->isDone($p->task_status);
            $overdueTasks = $leaves->filter(fn ($x) => !$this->isDone($x->task_status) && $x->due_date && Carbon::parse($x->due_date)->lt($today))->count();

            return [
                'id' => $p->id,
                'job_id' => $p->project_id,
                'job' => $jobName[$p->project_id] ?? null,
                'name' => $p->task_title,
                'status' => $p->task_status ?: 'Todo',
                'due_date' => $p->due_date ? Carbon::parse($p->due_date)->toDateString() : null,
                'tasks_total' => $total,
                'tasks_done' => $done,
                'overdue_tasks' => $overdueTasks,
                'progress' => $finished ? 100 : ($total ? (int) round($done / $total * 100) : 0),
                'hours' => round(($hours['by_project'][$p->id] ?? 0) / 3600, 1),
                'finished' => $finished,
                'overdue' => !$finished && $p->due_date && Carbon::parse($p->due_date)->lt($today),
            ];
        })->values();

        /* ------------------------------ tasks ------------------------------ */
        $statusCounts = $tasks->groupBy(fn ($t) => $t->task_status ?: 'Todo')->map->count()->sortDesc();
        $doneTasks = $tasks->filter(fn ($t) => $this->isDone($t->task_status));
        $openTasks = $tasks->reject(fn ($t) => $this->isDone($t->task_status));
        $overdue = $openTasks->filter(fn ($t) => $t->due_date && Carbon::parse($t->due_date)->lt($today));
        $dueSoon = $openTasks->filter(fn ($t) => $t->due_date && Carbon::parse($t->due_date)->between($today, $today->copy()->addDays(14)));

        // On-time delivery: finished tasks that have both a due date and a completed date.
        $judged = $doneTasks->filter(fn ($t) => $t->due_date && $t->completed_date);
        $onTime = $judged->filter(fn ($t) => Carbon::parse($t->completed_date)->lte(Carbon::parse($t->due_date)))->count();

        $completedOn = fn ($t) => Carbon::parse($t->completed_date ?: $t->updated_at)->startOfDay();
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = $today->copy()->startOfMonth()->subMonths($i);
            $months[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'label' => $m->format('M'), 'completed' => 0];
        }
        foreach ($doneTasks as $t) {
            $k = $completedOn($t)->format('Y-m');
            if (isset($months[$k])) {
                $months[$k]['completed']++;
            }
        }
        $last30 = $doneTasks->filter(fn ($t) => $completedOn($t)->gte($today->copy()->subDays(30)))->count();
        $prev30 = $doneTasks->filter(fn ($t) => $completedOn($t)->between($today->copy()->subDays(60), $today->copy()->subDays(31)))->count();

        // Coming up: open items with a due date (a project or one of its tasks), each tagged with its project.
        $upcoming = $all->filter(fn ($t) => !$this->isDone($t->task_status) && $t->due_date && empty($hasChildren[$t->id]) || (!$this->isDone($t->task_status) && $t->due_date && $root[$t->id] === $t->id))
            ->unique('id')
            ->sortBy('due_date')
            ->take(8)
            ->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->task_title,
                'project_id' => $root[$t->id],
                'project' => $root[$t->id] === $t->id ? null : $title($root[$t->id]),
                'is_project' => $root[$t->id] === $t->id,
                'due_date' => Carbon::parse($t->due_date)->toDateString(),
                'days' => (int) $today->diffInDays(Carbon::parse($t->due_date)->startOfDay(), false),
                'status' => $t->task_status,
                'priority' => $t->priority,
            ])->values();

        $recent = $doneTasks->sortByDesc(fn ($t) => $completedOn($t)->timestamp)->take(6)->map(fn ($t) => [
            'id' => $t->id,
            'title' => $t->task_title,
            'project_id' => $root[$t->id],
            'project' => $root[$t->id] === $t->id ? null : $title($root[$t->id]),
            'completed_on' => $completedOn($t)->toDateString(),
        ])->values();

        /* ------------------------------ hours report ------------------------------ */
        $weeks = [];
        for ($i = 11; $i >= 0; $i--) {
            $w = $today->copy()->startOfWeek()->subWeeks($i);
            $weeks[$w->toDateString()] = ['week' => $w->toDateString(), 'label' => $w->format('j M'), 'hours' => 0.0];
        }
        foreach ($hours['by_week'] as $weekStart => $sec) {
            if (isset($weeks[$weekStart])) {
                $weeks[$weekStart]['hours'] = round($sec / 3600, 1);
            }
        }
        $thisMonth = $hours['by_month'][$today->format('Y-m')] ?? 0;
        $lastMonth = $hours['by_month'][$today->copy()->subMonth()->format('Y-m')] ?? 0;
        $last30h = 0;
        foreach ($hours['by_day'] as $d => $sec) {
            if (Carbon::parse($d)->gte($today->copy()->subDays(29))) {
                $last30h += $sec;
            }
        }

        $hoursByProject = collect($hours['by_project'])
            ->map(fn ($sec, $pid) => ['project_id' => (int) $pid, 'name' => $title($pid), 'hours' => round($sec / 3600, 1)])
            ->filter(fn ($r) => $r['hours'] > 0)
            ->sortByDesc('hours')->values();
        $totalProjectHours = max(0.0001, $hoursByProject->sum('hours'));
        $hoursByProject = $hoursByProject->take(6)->map(fn ($r) => $r + ['pct' => (int) round($r['hours'] / $totalProjectHours * 100)])->values();

        $bestWeek = collect($weeks)->sortByDesc('hours')->first();
        $open = $projectRows->where('finished', false);

        return [
            'generated_at' => now()->toIso8601String(),
            'projects' => [
                'total' => $projects->count(),
                'open' => $open->count(),
                'in_progress' => $projects->filter(fn ($t) => strtolower((string) $t->task_status) === 'in progress')->count(),
                'completed' => $projectRows->where('finished', true)->count(),
                'overdue' => $projectRows->where('overdue', true)->count(),
            ],
            'tasks' => [
                'total' => $tasks->count(),
                'done' => $doneTasks->count(),
                'open' => $openTasks->count(),
                'overdue' => $overdue->count(),
                'due_soon' => $dueSoon->count(),
                'completion_pct' => $tasks->count() ? (int) round($doneTasks->count() / $tasks->count() * 100) : 0,
                'on_time_rate' => $judged->count() ? (int) round($onTime / $judged->count() * 100) : null,
                'on_time_sample' => $judged->count(),
                'completed_last_30' => $last30,
                'completed_prev_30' => $prev30,
            ],
            'hours' => [
                'all_time' => round($hours['total'] / 3600, 1),
                'this_month' => round($thisMonth / 3600, 1),
                'last_month' => round($lastMonth / 3600, 1),
                'last_30' => round($last30h / 3600, 1),
                'change_pct' => $lastMonth > 0 ? (int) round(($thisMonth - $lastMonth) / $lastMonth * 100) : null,
                'weekly_avg' => round(collect($weeks)->avg('hours') ?? 0, 1),
            ],
            'hours_by_week' => array_values($weeks),
            'hours_by_project' => $hoursByProject,
            'tasks_by_status' => $statusCounts->map(fn ($c, $s) => ['status' => $s, 'count' => $c])->values(),
            'completed_by_month' => array_values($months),
            'project_progress' => $open->sortBy([['overdue', 'desc'], ['due_date', 'asc']])->take(8)->values(),
            'upcoming' => $upcoming,
            'recently_completed' => $recent,
            'highlights' => [
                'best_week' => $bestWeek && $bestWeek['hours'] > 0 ? $bestWeek : null,
                'most_active_project' => $hoursByProject->first(),
                'busiest_status' => $statusCounts->keys()->first(),
                'active_projects' => $open->count(),
            ],
        ];
    }

    /**
     * Net worked seconds (idle excluded, the same maths as every other hours figure in the portal) for these tasks,
     * bucketed by project, day, week (Monday) and month. Aggregates only: nobody is named.
     *
     * @param  int[]  $taskIds
     * @param  array<int,int>  $taskProject  task id => project id
     */
    private function hours(array $taskIds, array $taskProject): array
    {
        $out = ['total' => 0, 'by_project' => [], 'by_day' => [], 'by_week' => [], 'by_month' => []];
        if (!$taskIds) {
            return $out;
        }

        foreach (array_chunk($taskIds, 800) as $chunk) {
            $sessions = WorkSession::whereIn('task_id', $chunk)->get(['id', 'task_id', 'start_date', 'start_time', 'end_date', 'end_time']);
            if ($sessions->isEmpty()) {
                continue;
            }

            $adjustments = [];
            foreach (array_chunk($sessions->pluck('id')->all(), 800) as $ids) {
                foreach (DB::table('session_time_adjustments')->whereIn('session_id', $ids)->get(['session_id', 'start_time', 'end_time']) as $a) {
                    $adjustments[$a->session_id][] = $a;
                }
            }

            foreach ($sessions as $s) {
                try {
                    $start = Carbon::parse($s->start_date . ' ' . $s->start_time);
                    $end = is_null($s->end_time) ? now() : Carbon::parse(($s->end_date ?? $s->start_date) . ' ' . $s->end_time);
                    $sec = $this->sessionWorkedSeconds($adjustments[$s->id] ?? [], $start, $end);
                } catch (\Throwable $e) {
                    continue;
                }
                if ($sec <= 0) {
                    continue;
                }

                $day = $start->copy()->startOfDay();
                $out['total'] += $sec;
                $pid = $taskProject[$s->task_id] ?? null;
                if ($pid) {
                    $out['by_project'][$pid] = ($out['by_project'][$pid] ?? 0) + $sec;
                }
                $out['by_day'][$day->toDateString()] = ($out['by_day'][$day->toDateString()] ?? 0) + $sec;
                $wk = $day->copy()->startOfWeek()->toDateString();
                $out['by_week'][$wk] = ($out['by_week'][$wk] ?? 0) + $sec;
                $mo = $day->format('Y-m');
                $out['by_month'][$mo] = ($out['by_month'][$mo] ?? 0) + $sec;
            }
        }

        return $out;
    }

    /* --------------------------------------------------------------- subscription */

    public function subscription(Request $request)
    {
        $user = Auth::user();
        $key = 'customer-dashboard:subscription:v3:' . $user->id . ':' . md5(strtolower((string) $user->email));
        $lastGood = $key . ':last';

        if ($request->boolean('fresh')) {
            Cache::forget($key);
        }

        $data = Cache::remember($key, now()->addMinutes(10), function () use ($user, $lastGood) {
            try {
                $r = app(\App\Services\CustomerSubscriptionSummary::class)->forUser($user);
                if (!empty($r['matched'])) {
                    Cache::put($lastGood, $r, now()->addDay());
                }

                return $r;
            } catch (\Throwable $e) {
                \Log::warning('Customer subscription summary failed for user ' . $user->id . ': ' . $e->getMessage());

                // If Stripe hiccups, keep showing the last good answer (up to a day old) instead of making the card vanish.
                if ($stale = Cache::get($lastGood)) {
                    return $stale + ['stale' => true, 'error' => true];
                }

                // `reason` / `detail` are harmless diagnostics (no keys, no customer data): they show in the browser's network tab
                // when the card does not appear, so it is easy to tell "not configured" from "no match" from "Stripe error".
                return ['matched' => false, 'error' => true, 'reason' => 'lookup_failed', 'detail' => (new \ReflectionClass($e))->getShortName()];
            }
        });

        // A failed lookup is not remembered, so the next visit tries again.
        if (!empty($data['error'])) {
            Cache::forget($key);
        }

        return response()->json($data);
    }

    /** The latest invoices, only fetched when the customer opens "recent invoices". */
    public function subscriptionInvoices()
    {
        $user = Auth::user();

        $data = Cache::remember('customer-dashboard:invoices:v1:' . $user->id, now()->addMinutes(5), function () use ($user) {
            try {
                return app(\App\Services\CustomerSubscriptionSummary::class)->recentInvoices($user);
            } catch (\Throwable $e) {
                \Log::warning('Customer invoice list failed for user ' . $user->id . ': ' . $e->getMessage());

                return ['invoices' => [], 'paid_total' => [], 'error' => true];
            }
        });

        return response()->json($data);
    }
}
