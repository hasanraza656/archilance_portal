<?php

namespace App\Services;

use App\Models\User;
use App\Traits\CalculatesIdleTime;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the chat's "Contact info" panel shows about the person you are talking to.
 *
 *  - for everyone: who they are, whether they are online, and the work you share - the jobs and tasks you
 *    are both on, newest activity first;
 *  - for admins, executives and a person's own manager: their open tasks and the time spent on each, plus
 *    today's and this week's worked hours.
 *
 * Worked time uses the same idle-aware maths as the work diary (CalculatesIdleTime), never a raw
 * end-minus-start, so the numbers here agree with every other screen.
 */
class ChatInsights
{
    use CalculatesIdleTime;

    private const LOOKBACK_DAYS = 90;

    public function forPair(User $me, User $other): array
    {
        $presence = DB::table('chat_presence')->where('user_id', $other->id)->value('last_seen_at');
        $online = $presence && Carbon::parse($presence)->gt(now()->subMinutes(2));

        $out = [
            'profile' => [
                'id' => $other->id,
                'name' => $other->name,
                'email' => ChatCore::isStaff($other) ? $other->email : null,
                'profile_pic' => $other->profile_pic,
                'user_role' => (int) $other->user_role,
                'employee_type' => $other->employee_type,
                'employee_team' => $other->employee_team,
                'joining_date' => $other->joining_date ? (string) $other->joining_date : null,
                'online' => $online,
                'last_seen_at' => ChatCore::iso($presence),
            ],
            'shared' => ['projects' => [], 'tasks' => []],
            'lead_view' => null,
        ];

        if (!ChatCore::isStaff($me) || !ChatCore::isStaff($other)) {
            return $out; // customers get the profile only
        }

        $out['shared'] = $this->shared($me->id, $other->id);

        $isLead = (int) $me->user_role === 2
            || (int) $me->user_role === 1
            || ($me->employee_type === 'Executive')
            || ((int) ($other->manager_id ?? 0) === (int) $me->id)
            || ((int) ($other->internee_manager_id ?? 0) === (int) $me->id);

        if ($isLead) {
            $out['lead_view'] = $this->workload($other->id);
        }

        return $out;
    }

    /* ----------------------------------------------------------------------------------------- */

    /** Tasks and jobs both people are on (assigned or have tracked time on), most recently worked first. */
    private function shared(int $a, int $b): array
    {
        $since = now()->subDays(self::LOOKBACK_DAYS)->toDateString();

        $taskSet = function (int $uid) use ($since) {
            $assigned = DB::table('task_assignees')->where('employee_id', $uid)->pluck('task_id');
            $worked = DB::table('work_sessions')->where('user_id', $uid)->whereNotNull('task_id')->where('start_date', '>=', $since)->distinct()->pluck('task_id');

            return $assigned->merge($worked)->unique()->values();
        };

        $sharedTaskIds = $taskSet($a)->intersect($taskSet($b))->values();
        $tasks = [];

        if ($sharedTaskIds->isNotEmpty()) {
            $rows = DB::table('project_tasks as t')
                ->join('projects as p', 'p.id', '=', 't.project_id')
                ->whereIn('t.id', $sharedTaskIds)
                ->whereNull('t.deleted_at')->whereNull('p.deleted_at')
                ->get(['t.id', 't.task_title', 't.task_status', 't.project_id', 't.due_date', 'p.project_name', 'p.status as project_status']);

            $sessions = $this->sessionsFor([$a, $b], $rows->pluck('id')->all(), $since);

            foreach ($rows as $r) {
                $mine = $sessions[$r->id][$a] ?? ['seconds' => 0, 'last' => null];
                $theirs = $sessions[$r->id][$b] ?? ['seconds' => 0, 'last' => null];
                $last = collect([$mine['last'], $theirs['last']])->filter()->max();
                $tasks[] = [
                    'id' => (int) $r->id,
                    'title' => $r->task_title,
                    'status' => $r->task_status,
                    'due_date' => $r->due_date,
                    'project_id' => (int) $r->project_id,
                    'project_name' => $r->project_name,
                    'my_seconds' => $mine['seconds'],
                    'their_seconds' => $theirs['seconds'],
                    'last_worked_at' => $last,
                ];
            }
            usort($tasks, fn ($x, $y) => strcmp((string) $y['last_worked_at'], (string) $x['last_worked_at']) ?: $y['id'] <=> $x['id']);
            $tasks = array_slice($tasks, 0, 8);
        }

        $projects = [];
        $seen = [];
        foreach ($tasks as $t) {
            if (isset($seen[$t['project_id']])) {
                continue;
            }
            $seen[$t['project_id']] = true;
            $projects[] = ['id' => $t['project_id'], 'name' => $t['project_name'], 'last_worked_at' => $t['last_worked_at']];
        }

        // Jobs you share by assignment alone (no task in common yet).
        $shareJobs = DB::table('project_assignees')->whereIn('employee_id', [$a, $b])->select('project_id')->groupBy('project_id')->havingRaw('COUNT(DISTINCT employee_id) = 2')->pluck('project_id');
        if ($shareJobs->isNotEmpty()) {
            $extra = DB::table('projects')->whereIn('id', $shareJobs)->whereNull('deleted_at')->whereNotIn('id', array_keys($seen))->orderByDesc('updated_at')->limit(5)->get(['id', 'project_name']);
            foreach ($extra as $p) {
                $projects[] = ['id' => (int) $p->id, 'name' => $p->project_name, 'last_worked_at' => null];
            }
        }

        return ['projects' => array_slice($projects, 0, 8), 'tasks' => $tasks];
    }

    /** One person's open tasks with time spent, and today / this-week hours. */
    private function workload(int $uid): array
    {
        $since = now()->subDays(30)->toDateString();
        $taskIds = DB::table('task_assignees')->where('employee_id', $uid)->pluck('task_id');

        $rows = $taskIds->isEmpty() ? collect() : DB::table('project_tasks as t')
            ->join('projects as p', 'p.id', '=', 't.project_id')
            ->whereIn('t.id', $taskIds)->whereNull('t.deleted_at')->whereNull('p.deleted_at')
            ->where('t.task_status', '!=', 'Completed')
            ->orderByDesc('t.updated_at')->limit(15)
            ->get(['t.id', 't.task_title', 't.task_status', 't.due_date', 't.priority', 'p.id as project_id', 'p.project_name']);

        $sessions = $this->sessionsFor([$uid], $rows->pluck('id')->all(), $since);

        $tasks = $rows->map(function ($r) use ($sessions, $uid) {
            $s = $sessions[$r->id][$uid] ?? ['seconds' => 0, 'last' => null];

            return [
                'id' => (int) $r->id,
                'title' => $r->task_title,
                'status' => $r->task_status,
                'priority' => $r->priority,
                'due_date' => $r->due_date,
                'project_id' => (int) $r->project_id,
                'project_name' => $r->project_name,
                'seconds_30d' => $s['seconds'],
                'last_worked_at' => $s['last'],
            ];
        })->sortByDesc(fn ($t) => (string) $t['last_worked_at'])->values()->all();

        [$today, $week] = $this->todayAndWeek($uid);

        return ['today_seconds' => $today, 'week_seconds' => $week, 'tasks' => $tasks];
    }

    /**
     * Worked seconds + latest activity per [task][user], idle removed.
     *
     * @return array<int, array<int, array{seconds:int,last:?string}>>
     */
    private function sessionsFor(array $userIds, array $taskIds, string $since): array
    {
        if (!$taskIds || !$userIds) {
            return [];
        }

        $sessions = DB::table('work_sessions')->whereIn('user_id', $userIds)->whereIn('task_id', $taskIds)->where('start_date', '>=', $since)
            ->get(['id', 'user_id', 'task_id', 'start_date', 'start_time', 'end_date', 'end_time']);
        $adj = $sessions->isEmpty() ? collect() : DB::table('session_time_adjustments')->whereIn('session_id', $sessions->pluck('id'))->get(['session_id', 'start_time', 'end_time'])->groupBy('session_id');

        $out = [];
        foreach ($sessions as $s) {
            $secs = $this->secondsOf($s, $adj->get($s->id, collect()));
            $slot = &$out[$s->task_id][$s->user_id];
            $slot ??= ['seconds' => 0, 'last' => null];
            $slot['seconds'] += $secs;
            $start = trim($s->start_date . ' ' . $s->start_time);
            if ($slot['last'] === null || $start > $slot['last']) {
                $slot['last'] = $start;
            }
            unset($slot);
        }

        return $out;
    }

    /**
     * Idle-free time on a set of tasks over the lookback window: total, the viewer's own share, latest activity,
     * and seconds per person (used by the tag side panel).
     *
     * @return array{total:int,mine:int,last:?string,by_user:array<int,int>}
     */
    public function taskTotals(array $taskIds, int $viewerId): array
    {
        $out = ['total' => 0, 'mine' => 0, 'last' => null, 'by_user' => []];
        if (!$taskIds) {
            return $out;
        }
        $since = now()->subDays(self::LOOKBACK_DAYS)->toDateString();
        $users = DB::table('work_sessions')->whereIn('task_id', $taskIds)->where('start_date', '>=', $since)->distinct()->pluck('user_id')->all();
        foreach ($this->sessionsFor($users, $taskIds, $since) as $perUser) {
            foreach ($perUser as $uid => $slot) {
                $out['total'] += $slot['seconds'];
                $out['by_user'][$uid] = ($out['by_user'][$uid] ?? 0) + $slot['seconds'];
                if ((int) $uid === $viewerId) {
                    $out['mine'] += $slot['seconds'];
                }
                if ($slot['last'] !== null && ($out['last'] === null || $slot['last'] > $out['last'])) {
                    $out['last'] = $slot['last'];
                }
            }
        }
        arsort($out['by_user']);

        return $out;
    }

    /** @return array{0:int,1:int} [today, this week (Mon-)] worked seconds */
    private function todayAndWeek(int $uid): array
    {
        $today = now()->toDateString();
        $weekStart = now()->startOfWeek(Carbon::MONDAY)->toDateString();

        $sessions = DB::table('work_sessions')->where('user_id', $uid)->where('start_date', '>=', $weekStart)->where('start_date', '<=', $today)
            ->get(['id', 'start_date', 'start_time', 'end_date', 'end_time']);
        $adj = $sessions->isEmpty() ? collect() : DB::table('session_time_adjustments')->whereIn('session_id', $sessions->pluck('id'))->get(['session_id', 'start_time', 'end_time'])->groupBy('session_id');

        $t = 0;
        $w = 0;
        foreach ($sessions as $s) {
            $secs = $this->secondsOf($s, $adj->get($s->id, collect()));
            $w += $secs;
            if ($s->start_date === $today) {
                $t += $secs;
            }
        }

        return [$t, $w];
    }

    private function secondsOf(object $s, $adjustments): int
    {
        try {
            $start = Carbon::parse($s->start_date . ' ' . $s->start_time);
            // A session still running counts up to now; an old unclosed one counts as nothing (it would invent days of work).
            if (empty($s->end_time)) {
                if ($start->toDateString() !== now()->toDateString()) {
                    return 0;
                }
                $end = now();
            } else {
                $end = Carbon::parse(($s->end_date ?: $s->start_date) . ' ' . $s->end_time);
            }

            return $this->sessionWorkedSeconds($adjustments, $start, $end);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
