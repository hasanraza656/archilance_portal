<?php

namespace App\Services;

use App\Models\ProjectTask;
use App\Models\TaskAssignee;
use App\Models\User;
use App\Models\WorkSession;
use App\Traits\CalculatesIdleTime;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Who worked for which project manager, and for how long.
 *
 * Ratings are decided by the work done, not by the org chart: an employee is
 * rated by the project manager(s) of the first-level tasks ("projects" in the UI)
 * they tracked time on. Every tracked session is resolved up to its ROOT task
 * (parent_task_id IS NULL); the managers of that root — task_assignees rows with
 * is_manager = 1 — each get the session's net seconds credited to them.
 *
 * Rollout fallback: work on a project that has NO manager set yet is credited to
 * the employee's reporting manager (users.manager_id), so ratings don't vanish
 * while the team is still filling project managers in. Once a project has a
 * manager, the reporting line no longer matters for it.
 *
 * Hours are NET (idle removed) and a session belongs to the day it started —
 * the same conventions as the work diary and the Summary.
 */
class ProjectManagerHours
{
    use CalculatesIdleTime;

    /** Weekly rating: an employee needs more than this many seconds for a manager in the week. */
    public const WEEKLY_MIN_SECONDS = 5 * 3600;

    /**
     * Bi-weekly rating of a project manager: total seconds for that manager across
     * the two-week period (the same 5 h a week as the weekly rating, doubled).
     */
    public const PERIOD_MIN_SECONDS = 10 * 3600;

    private const CACHE_TTL = 600;

    /**
     * Credits per (bucket, employee, manager).
     *
     * @param  string  $bucket    'week' (bucket = Monday), 'month' (bucket = 1st of month) or 'all'
     * @param  bool    $fallback  credit work on manager-less projects to the reporting manager
     *                            (on for the weekly rating's rollout; off for the bi-weekly one, which
     *                            only ever rates real project managers)
     * @return array<string, array{bucket: string, employee_id: int, manager_id: int, seconds: int, root_ids: int[]}>
     */
    public function credits(Carbon $from, Carbon $to, string $bucket = 'week', bool $fallback = true): array
    {
        $key = 'pm-hours:' . $bucket . ($fallback ? '' : ':strict') . ':' . $from->toDateString() . ':' . $to->toDateString();

        try {
            return Cache::remember($key, self::CACHE_TTL, fn () => $this->compute($from, $to, $bucket, $fallback));
        } catch (\Throwable $e) {
            // Cache unavailable — compute directly rather than fail the request.
            return $this->compute($from, $to, $bucket, $fallback);
        }
    }

    private function bucketOf(string $startDate, string $bucket): string
    {
        $d = Carbon::parse($startDate);

        return match ($bucket) {
            'week' => $d->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
            'month' => $d->copy()->startOfMonth()->toDateString(),
            default => 'all',
        };
    }

    private function compute(Carbon $from, Carbon $to, string $bucket, bool $fallback = true): array
    {
        $sessions = WorkSession::whereBetween('start_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('end_time') // unclosed sessions contribute nothing until they end
            ->get(['id', 'user_id', 'task_id', 'start_date', 'start_time', 'end_date', 'end_time']);

        if ($sessions->isEmpty()) {
            return [];
        }

        $adjustments = collect();
        foreach ($sessions->pluck('id')->chunk(2000) as $ids) {
            $adjustments = $adjustments->concat(
                DB::table('session_time_adjustments')->whereIn('session_id', $ids)->get(['session_id', 'start_time', 'end_time'])
            );
        }
        $adjustments = $adjustments->groupBy('session_id');

        $rootOf = $this->resolveRoots($sessions->pluck('task_id')->unique()->filter()->values()->all());
        $managersByRoot = $this->managersByRoot();
        $reportingManager = User::where('user_role', 3)->whereNotNull('manager_id')->pluck('manager_id', 'id');

        $out = [];

        foreach ($sessions as $s) {
            $root = $rootOf[$s->task_id] ?? null;
            if (!$root) {
                continue;
            }

            try {
                $start = Carbon::parse($s->start_date . ' ' . $s->start_time);
                $end = Carbon::parse(($s->end_date ?: $s->start_date) . ' ' . $s->end_time);
            } catch (\Throwable $e) {
                continue;
            }

            $seconds = $this->sessionWorkedSeconds($adjustments->get($s->id, collect()), $start, $end);
            if ($seconds <= 0) {
                continue;
            }

            $managers = $managersByRoot[$root] ?? [];
            if ($fallback && empty($managers) && isset($reportingManager[$s->user_id])) {
                $managers = [(int) $reportingManager[$s->user_id]]; // rollout fallback: project has no manager yet
            }

            $b = $this->bucketOf($s->start_date, $bucket);

            foreach ($managers as $managerId) {
                if ((int) $managerId === (int) $s->user_id) {
                    continue; // nobody rates themselves
                }

                $k = $b . '|' . $s->user_id . '|' . $managerId;
                if (!isset($out[$k])) {
                    $out[$k] = [
                        'bucket' => $b,
                        'employee_id' => (int) $s->user_id,
                        'manager_id' => (int) $managerId,
                        'seconds' => 0,
                        'root_ids' => [],
                    ];
                }
                $out[$k]['seconds'] += $seconds;
                $out[$k]['root_ids'][$root] = $root;
            }
        }

        foreach ($out as &$row) {
            $row['root_ids'] = array_values($row['root_ids']);
        }
        unset($row);

        return $out;
    }

    /**
     * task_id => root task id, walking parent_task_id up (batched, so a big
     * month costs a handful of queries, not one per session).
     *
     * @param  int[]  $taskIds
     * @return array<int, int>
     */
    private function resolveRoots(array $taskIds): array
    {
        $parents = []; // id => parent_task_id|null
        $need = $taskIds;

        for ($round = 0; $round < 12 && !empty($need); $round++) {
            $next = [];

            foreach (array_chunk($need, 1500) as $chunk) {
                foreach (ProjectTask::whereIn('id', $chunk)->get(['id', 'parent_task_id']) as $t) {
                    $parents[$t->id] = $t->parent_task_id;
                    if ($t->parent_task_id && !array_key_exists($t->parent_task_id, $parents)) {
                        $next[$t->parent_task_id] = $t->parent_task_id;
                    }
                }
            }

            $need = array_values($next);
        }

        $rootOf = [];
        foreach ($taskIds as $id) {
            $cur = $id;
            $guard = 0;
            while (isset($parents[$cur]) && $parents[$cur] && $guard++ < 12) {
                $cur = $parents[$cur];
            }
            if (array_key_exists($cur, $parents)) {
                $rootOf[$id] = (int) $cur;
            }
        }

        return $rootOf;
    }

    /** @return array<int, int[]> root task id => ids of its project managers */
    public function managersByRoot(): array
    {
        if (!TaskAssignee::supportsManagers()) {
            return [];
        }

        $map = [];
        foreach (TaskAssignee::where('is_manager', true)->get(['task_id', 'employee_id']) as $a) {
            $map[(int) $a->task_id][] = (int) $a->employee_id;
        }

        return $map;
    }

    /**
     * Titles of root tasks (the "projects" shown to people as context on a card).
     *
     * @param  int[]  $rootIds
     * @return array<int, string>
     */
    public function rootTitles(array $rootIds): array
    {
        if (empty($rootIds)) {
            return [];
        }

        return ProjectTask::whereIn('id', $rootIds)->pluck('task_title', 'id')->all();
    }
}
