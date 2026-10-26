<?php

namespace App\Services\Mcp\Tools;

use App\Models\ProjectTask;
use App\Models\User;
use App\Services\Mcp\Present;
use App\Services\Mcp\S;
use App\Services\Mcp\Tool;
use App\Services\Mcp\ToolContext;
use App\Services\Mcp\ToolError;
use Carbon\Carbon;

/**
 * Work diaries, hours and company-wide reports.
 * Hours always come from the portal's own calculations (idle time removed, overlaps merged) - never recomputed here.
 */
final class TimeTools
{
    private const SECTIONS = ['totals', 'employee_hours', 'customer_hours', 'project_hours', 'workload', 'idle_ratio', 'leave_summary', 'task_status', 'project_health', 'tracking_health', 'leave_availability', 'employee_activity', 'previous_totals', 'previous_leave_summary'];

    /** @return Tool[] */
    public static function all(): array
    {
        return [self::workDiary(), self::summaryReport(), self::averageHours(), self::addManualTime()];
    }

    private static function workDiary(): Tool
    {
        return new Tool(
            'work_diary',
            'Work diary of an employee',
            'One employee\'s tracked time between two dates (default today): total worked, idle time, totals per day and per task, and optionally every session. Worked time already has idle time removed, exactly like the portal\'s Work Diary. Screenshots are not returned (only a count).',
            [
                'employee_id' => S::int('Employee user id (use find_people to look it up).', 1),
                'start_date' => S::date('From (default today)'),
                'end_date' => S::date('To (default = start_date)'),
                'task_id' => S::int('Only this task (and its sub-tasks).'),
                'project_id' => S::int('Only this project.'),
                'include_sessions' => S::bool('Also list every session (default false).'),
            ],
            ['employee_id'],
            Tool::READ,
            function (array $a, ToolContext $c) {
                $emp = User::withTrashed()->find($a['employee_id']);
                if (!$emp) {
                    throw new ToolError('Employee not found.');
                }
                $start = $a['start_date'] ?? $c->today();
                $end = $a['end_date'] ?? $start;
                if ($end < $start) {
                    throw new ToolError('end_date is before start_date.');
                }

                $r = $c->api->must($c->api->get('admin/work-session', array_filter([
                    'employee_id' => $emp->id,
                    'start_date' => $start,
                    'end_date' => $end,
                    'task_id' => $a['task_id'] ?? null,
                    'project_id' => $a['project_id'] ?? null,
                ])));

                $byDay = [];
                $byTask = [];
                $sessions = [];
                $idleTotal = 0;
                $netTotal = 0;
                $shots = 0;
                $running = 0;
                foreach ((array) ($r['data'] ?? []) as $s) {
                    $net = (int) ($s['raw_calculation']['net_seconds'] ?? 0);
                    $idle = (int) ($s['idle_seconds'] ?? 0);
                    $idleTotal += $idle;
                    $netTotal += $net;
                    $shots += count((array) ($s['screenshots'] ?? []));
                    $day = (string) ($s['start_date'] ?? '');
                    $byDay[$day] = ($byDay[$day] ?? ['seconds' => 0, 'sessions' => 0]);
                    $byDay[$day]['seconds'] += $net;
                    $byDay[$day]['sessions']++;
                    $tid = (int) ($s['task_id'] ?? 0);
                    $title = $s['task_detail']['task_title'] ?? null;
                    $byTask[$tid] = $byTask[$tid] ?? ['task_id' => $tid, 'title' => $title, 'seconds' => 0, 'sessions' => 0];
                    $byTask[$tid]['seconds'] += $net;
                    $byTask[$tid]['sessions']++;
                    if (($s['total_time'] ?? '') === 'Running') {
                        $running++;
                    }
                    $sessions[] = Present::clean([
                        'start' => trim(($s['start_date'] ?? '') . ' ' . ($s['start_time'] ?? '')),
                        'end' => !empty($s['end_time']) ? trim(($s['end_date'] ?? $s['start_date'] ?? '') . ' ' . $s['end_time']) : 'running',
                        'task' => $title,
                        'worked' => Present::hm($net),
                        'idle' => Present::hm($idle),
                        'type' => $s['type'] ?? null,
                        'memo' => $s['memo_content'] ?? null,
                    ]);
                }
                ksort($byDay);
                uasort($byTask, fn ($x, $y) => $y['seconds'] <=> $x['seconds']);

                $days = [];
                foreach ($byDay as $d => $v) {
                    $days[] = ['date' => $d, 'worked' => Present::hm($v['seconds']), 'sessions' => $v['sessions']];
                }
                $tasks = [];
                foreach ($byTask as $v) {
                    $tasks[] = Present::clean(['task_id' => $v['task_id'], 'title' => $v['title'], 'worked' => Present::hm($v['seconds']), 'sessions' => $v['sessions']]);
                }

                $out = [
                    'employee' => ['id' => (int) $emp->id, 'name' => $emp->name],
                    'range' => ['from' => $start, 'to' => $end],
                    'total_worked' => Present::hm($netTotal),
                    'total_idle_removed' => Present::hm($idleTotal),
                    'sessions' => count($sessions),
                    'timer_running_now' => $running > 0 ? true : null,
                    'screenshots_taken' => $shots,
                    'by_day' => $days,
                    'by_task' => array_slice($tasks, 0, 40),
                ];
                if (!empty($a['include_sessions'])) {
                    $out['session_list'] = array_slice($sessions, 0, 150);
                }
                if (count($r['data'] ?? []) >= 800) {
                    $out['_note'] = 'The portal caps one diary query at 800 sessions - narrow the date range for exact totals.';
                }

                return Present::clean($out);
            }
        );
    }

    private static function summaryReport(): Tool
    {
        return new Tool(
            'summary_report',
            'Company summary report',
            'The portal\'s Summary page as data: hours worked, idle ratio, manual time, per-employee and per-project hours, workload, task status snapshot, project health, tracking health, leave counts and availability for a date range (default: this week so far), with the previous period for comparison. Pick sections to keep it short. Values with "seconds" in their name also come with a readable "hm" twin.',
            [
                'start_date' => S::date('From (default Monday of this week)'),
                'end_date' => S::date('To (default today)'),
                'sections' => ['type' => 'array', 'description' => 'Which parts to return. Default: totals, employee_hours, project_hours, task_status, leave_summary. Options: ' . implode(', ', self::SECTIONS) . '.', 'items' => ['type' => 'string', 'enum' => self::SECTIONS]],
                'limit' => S::limit(25, 100),
            ],
            [],
            Tool::READ,
            function (array $a, ToolContext $c) {
                if (($a['start_date'] ?? null) && ($a['end_date'] ?? null) && $a['end_date'] < $a['start_date']) {
                    throw new ToolError('end_date is before start_date.');
                }
                $q = [];
                if (!empty($a['start_date']) && !empty($a['end_date'])) {
                    $q = ['start_date' => $a['start_date'], 'end_date' => $a['end_date']];
                } elseif (!empty($a['start_date']) || !empty($a['end_date'])) {
                    throw new ToolError('Pass both start_date and end_date, or neither.');
                }
                $r = $c->api->must($c->api->get('admin/summary', $q));

                $want = $a['sections'] ?? ['totals', 'employee_hours', 'project_hours', 'task_status', 'leave_summary'];
                $limit = $c->limit($a, 25, 100);
                $out = ['range' => $r['range'] ?? null, 'previous_range' => in_array('previous_totals', $want, true) || in_array('previous_leave_summary', $want, true) ? ($r['previous_range'] ?? null) : null];
                foreach ($want as $key) {
                    if (!array_key_exists($key, $r)) {
                        continue;
                    }
                    $v = $r[$key];
                    if (is_array($v) && array_is_list($v) && count($v) > $limit) {
                        $out[$key] = ['total' => count($v), 'shown' => $limit, 'items' => array_slice($v, 0, $limit)];
                    } else {
                        $out[$key] = $v;
                    }
                }

                return Present::durations(Present::clean($out));
            }
        );
    }

    private static function averageHours(): Tool
    {
        return new Tool(
            'employee_average_hours',
            'Average hours per day',
            'Average hours per working day for one employee over a range (default this week), excluding approved leave and (when the range includes today) today\'s unfinished day.',
            [
                'employee_id' => S::int('Employee user id.', 1),
                'start_date' => S::date('From'),
                'end_date' => S::date('To'),
            ],
            ['employee_id'],
            Tool::READ,
            function (array $a, ToolContext $c) {
                if (!empty($a['start_date']) xor !empty($a['end_date'])) {
                    throw new ToolError('Pass both start_date and end_date, or neither.');
                }
                $r = $c->api->must($c->api->get('admin/summary/employee/' . $a['employee_id'] . '/average', array_filter(['start_date' => $a['start_date'] ?? null, 'end_date' => $a['end_date'] ?? null])));
                $emp = User::withTrashed()->find($a['employee_id']);

                return Present::durations(Present::clean(['employee' => $emp ? ['id' => (int) $emp->id, 'name' => $emp->name] : null] + $r));
            }
        );
    }

    private static function addManualTime(): Tool
    {
        return new Tool(
            'add_manual_time',
            'Add manual time for an employee',
            'Record a block of manual work time for an EMPLOYEE on a task (like an employee\'s own manual entry; it counts toward hours and payroll). Needs confirm=true.',
            [
                'employee_id' => S::int('Employee user id.', 1),
                'task_id' => S::int('Task id the time belongs to.', 1),
                'start_date' => S::date('Start date'),
                'start_time' => S::str('Start time HH:MM (24-hour).', 8),
                'end_date' => S::date('End date'),
                'end_time' => S::str('End time HH:MM (24-hour).', 8),
                'memo' => S::str('Note shown in the diary.', 2000),
            ],
            ['employee_id', 'task_id', 'start_date', 'start_time', 'end_date', 'end_time'],
            Tool::DANGER,
            function (array $a, ToolContext $c) {
                self::checkTimes($a);
                $r = $c->api->must($c->api->post('admin/other-manual-time', array_filter([
                    'user_id' => $a['employee_id'],
                    'task_id' => $a['task_id'],
                    'start_date' => $a['start_date'],
                    'start_time' => $a['start_time'],
                    'end_date' => $a['end_date'],
                    'end_time' => $a['end_time'],
                    'memo_content' => $a['memo'] ?? null,
                ], fn ($v) => $v !== null)));

                return ['added' => true, 'session_id' => $r['work_session']['id'] ?? null];
            },
            function (array $a, ToolContext $c) {
                self::checkTimes($a);
                $emp = User::find($a['employee_id']);
                $task = ProjectTask::find($a['task_id']);
                $mins = (int) Carbon::parse($a['start_date'] . ' ' . $a['start_time'])->diffInMinutes(Carbon::parse($a['end_date'] . ' ' . $a['end_time']), false);

                return 'Add ' . Present::hm($mins * 60) . ' of MANUAL time for ' . ($emp->name ?? "user #{$a['employee_id']}") . ' on task "' . ($task->task_title ?? "#{$a['task_id']}") . "\" from {$a['start_date']} {$a['start_time']} to {$a['end_date']} {$a['end_time']}. This counts toward their worked hours and payroll.";
            }
        );
    }

    private static function checkTimes(array $a): void
    {
        foreach (['start_time', 'end_time'] as $k) {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $a[$k])) {
                throw new ToolError("'{$k}' must be a 24-hour time like 09:30.");
            }
        }
        if (Carbon::parse($a['end_date'] . ' ' . $a['end_time'])->lte(Carbon::parse($a['start_date'] . ' ' . $a['start_time']))) {
            throw new ToolError('The end must be after the start.');
        }
    }
}
