<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\ProjectTask;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Emails each employee a single digest of their tasks whose due date is
 * approaching (or only just overdue) and that still need THEIR action.
 * Idempotent: a task is only included once per day per user (tracked via an
 * in-app reminder row), so it's safe even if the scheduler runs it more than once.
 *
 * What is deliberately left out (these used to be emailed every single morning,
 * forever, which is why people stopped trusting the digest):
 *  - tasks overdue by more than --overdue-days (default 7): after that they are
 *    abandoned or stale data, not something a daily nudge will fix;
 *  - tasks whose ball is not in the employee's court: Completed / Done,
 *    Client Review, On Hold, Awaiting Info (and cancelled / closed);
 *  - sub-tasks that sit under a first-level task which is already Completed;
 *  - tasks of a job (DB "project") that is Completed or On Hold.
 */
class DueDateReminders extends Command
{
    protected $signature = 'notify:due-reminders {--days=2} {--overdue-days=7}';
    protected $description = 'Email employees a digest of their tasks with an approaching due date.';

    /** Statuses where the next move belongs to someone else (or there is no next move). */
    private const QUIET_TASK_STATUSES = ['completed', 'done', 'client review', 'on hold', 'awaiting info', 'cancelled', 'canceled', 'closed'];

    /** Job (DB "project") statuses whose tasks are not nagged about. */
    private const QUIET_JOB_STATUSES = ['completed', 'on hold', 'cancelled', 'canceled', 'closed'];

    private function isQuiet(?string $status, array $list): bool
    {
        return in_array(strtolower(trim((string) $status)), $list, true);
    }

    /**
     * Tasks that deserve a reminder today: due within the next $days days, or overdue by at
     * most $overdueDays, still actionable, with an assignee list loaded.
     */
    public function eligibleTasks(Carbon $today, int $days = 2, int $overdueDays = 7)
    {
        $limit = $today->copy()->addDays($days);
        $from  = $today->copy()->subDays($overdueDays);

        $tasks = ProjectTask::query()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '>=', $from)
            ->whereDate('due_date', '<=', $limit)
            ->with(['assignees.user', 'project'])
            ->get()
            ->filter(fn ($t) => !$this->isQuiet($t->task_status, self::QUIET_TASK_STATUSES))
            ->filter(fn ($t) => !$this->isQuiet(optional($t->project)->status, self::QUIET_JOB_STATUSES));

        if ($tasks->isEmpty()) {
            return $tasks->values();
        }

        // Sub-tasks of a first-level task that is already Completed are done in practice.
        $completedAncestors = $this->completedAncestorMap($tasks);

        return $tasks->reject(fn ($t) => isset($completedAncestors[$t->id]))->values();
    }

    /** @return array<int, true> ids of the given tasks that have a Completed/Done ancestor */
    private function completedAncestorMap($tasks): array
    {
        $parents = []; // id => [parent_task_id, status]
        $need    = $tasks->pluck('parent_task_id')->filter()->unique()->values()->all();

        for ($round = 0; $round < 12 && !empty($need); $round++) {
            $next = [];
            foreach (ProjectTask::whereIn('id', $need)->get(['id', 'parent_task_id', 'task_status']) as $p) {
                $parents[$p->id] = [$p->parent_task_id, $p->task_status];
                if ($p->parent_task_id && !isset($parents[$p->parent_task_id])) {
                    $next[$p->parent_task_id] = $p->parent_task_id;
                }
            }
            $need = array_values($next);
        }

        $out = [];
        foreach ($tasks as $t) {
            $cur   = $t->parent_task_id;
            $guard = 0;
            while ($cur && isset($parents[$cur]) && $guard++ < 12) {
                if (in_array(strtolower(trim((string) $parents[$cur][1])), ['completed', 'done'], true)) {
                    $out[$t->id] = true;
                    break;
                }
                $cur = $parents[$cur][0];
            }
        }

        return $out;
    }

    public function handle()
    {
        $days        = max(0, (int) $this->option('days'));
        $overdueDays = max(0, (int) $this->option('overdue-days'));
        $today       = Carbon::today();

        $tasks = $this->eligibleTasks($today, $days, $overdueDays);

        // Group by employee (skip customers and users without an email).
        $byEmployee = [];
        foreach ($tasks as $task) {
            foreach ($task->assignees as $assignee) {
                $u = $assignee->user;
                if (!$u || empty($u->email)) {
                    continue;
                }
                if (in_array((int) $u->user_role, [4, 5], true)) {
                    continue; // never email customers
                }
                $byEmployee[$u->id]['user'] = $u;
                $byEmployee[$u->id]['tasks'][] = $task;
            }
        }

        $emailsSent = 0;

        foreach ($byEmployee as $uid => $bucket) {
            $user = $bucket['user'];

            // Only tasks not already reminded to this user today.
            $freshTasks = [];
            foreach ($bucket['tasks'] as $task) {
                $already = Notification::where('user_id', $uid)
                    ->where('notification_type', 'task_due_reminder')
                    ->where('project_id', $task->id)
                    ->whereDate('created_at', $today)
                    ->exists();
                if (!$already) {
                    $freshTasks[] = $task;
                }
            }

            if (empty($freshTasks)) {
                continue;
            }

            // In-app reminder per task (also acts as the per-day dedupe marker).
            foreach ($freshTasks as $task) {
                insertNotificationWithNature(
                    $uid,
                    $uid,
                    'task_due_reminder',
                    'Reminder: "' . $task->task_title . '" is due ' . $this->dueLabel($task->due_date),
                    'warning',
                    $task->id
                );
            }

            // Build + send the digest email.
            $items = [];
            foreach ($freshTasks as $task) {
                $projectName = optional($task->project)->project_name;
                $items[] = [
                    'title' => $task->task_title,
                    'meta'  => trim(($projectName ? $projectName . ' • ' : '') . 'Due ' . $this->dueLabel($task->due_date)),
                    'badge' => ['text' => $task->task_status ?: 'Backlog', 'kind' => 'status'],
                    'url'   => frontendUrl('/project/' . $task->id),
                ];
            }

            $count = count($items);
            sendNotificationEmail(
                $user,
                '⏰ You have ' . $count . ' task' . ($count > 1 ? 's' : '') . ' due soon — Archilance',
                [
                    'emoji'   => '⏰',
                    'heading' => 'Tasks due soon',
                    'intro'   => 'Here ' . ($count > 1 ? 'are' : 'is') . ' your ' . $count . ' task' . ($count > 1 ? 's' : '')
                        . ' with an approaching due date. Please make sure ' . ($count > 1 ? 'they’re' : 'it’s') . ' on track.',
                    'items'   => $items,
                    'cta'     => ['text' => 'View my tasks', 'url' => frontendUrl('/members')],
                    'signoff' => 'Tip: keep your task statuses up to date as you make progress.',
                ],
                'due_reminder'
            );
            $emailsSent++;
        }

        $this->info("Due-date reminders processed. Digest emails sent: {$emailsSent}");
        return self::SUCCESS;
    }

    private function dueLabel($dueDate): string
    {
        $due   = Carbon::parse($dueDate)->startOfDay();
        $today = Carbon::today();
        $diff  = (int) $today->diffInDays($due, false); // negative = overdue

        if ($diff === 0) {
            return 'today (' . $due->format('M j') . ')';
        }
        if ($diff === 1) {
            return 'tomorrow (' . $due->format('M j') . ')';
        }
        if ($diff < 0) {
            $d = abs($diff);
            return 'overdue by ' . $d . ' day' . ($d > 1 ? 's' : '') . ' (' . $due->format('M j') . ')';
        }
        return 'in ' . $diff . ' days (' . $due->format('M j') . ')';
    }
}
