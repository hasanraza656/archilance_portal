<?php

namespace App\Services\Mcp\Tools;

use App\Models\Project;
use App\Models\ProjectAssignee;
use App\Models\ProjectTask;
use App\Models\TaskAssignee;
use App\Models\User;
use App\Services\Mcp\NeedsConfirm;
use App\Services\Mcp\Present;
use App\Services\Mcp\S;
use App\Services\Mcp\Tool;
use App\Services\Mcp\ToolContext;
use App\Services\Mcp\ToolError;
use Carbon\Carbon;

/**
 * Projects ("jobs" in the portal), their tasks and sub-tasks, assignees, comments and project chat.
 * Writes go through the portal's own admin endpoints so notifications / e-mails / validation are identical.
 * Nothing here deletes anything: project and task deletes are permanent in this portal, so they stay in the web app.
 */
final class ProjectTools
{
    public const STATUSES = ['Backlog', 'On Hold', 'Awaiting Info', 'In Progress', 'In-house review', 'Client Review', 'Completed'];
    public const PRIORITIES = ['Low', 'Normal', 'High', 'Urgent'];

    private const GLOSSARY = ' In this portal a "project" (job) holds tasks; tasks can have sub-tasks to any depth.';

    /** @return Tool[] */
    public static function all(): array
    {
        return [
            self::listProjects(),
            self::getProject(),
            self::createProject(),
            self::updateProject(),
            self::setProjectStatus(),
            self::setProjectTeam(),
            self::listTasks(),
            self::getTask(),
            self::createTask(),
            self::updateTask(),
            self::setTaskAssignees(),
            self::listTaskComments(),
            self::addTaskComment(),
            self::projectChatMessages(),
            self::projectChatSend(),
        ];
    }

    /* ------------------------------------------------------------------ helpers */

    private static function person($u): ?array
    {
        return Present::person($u);
    }

    private static function names(iterable $assignees): array
    {
        $out = [];
        foreach ($assignees as $a) {
            $u = is_array($a) ? ($a['user'] ?? null) : ($a->user ?? null);
            $p = self::person($u);
            if ($p) {
                $out[] = $p;
            }
        }

        return $out;
    }

    private static function like(string $term): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($term)) . '%';
    }

    private static function taskRow(ProjectTask $t): array
    {
        return Present::clean([
            'id' => (int) $t->id,
            'title' => $t->task_title,
            'project' => $t->relationLoaded('project') && $t->project ? ['id' => (int) $t->project->id, 'name' => $t->project->project_name] : ['id' => (int) $t->project_id],
            'parent_task_id' => $t->parent_task_id ? (int) $t->parent_task_id : null,
            'status' => $t->task_status,
            'priority' => $t->priority,
            'due_date' => $t->due_date,
            'completed_date' => $t->completed_date,
            'assignees' => $t->relationLoaded('assignees') ? self::names($t->assignees) : null,
            'sub_tasks' => isset($t->sub_tasks_count) ? (int) $t->sub_tasks_count : null,
        ]);
    }

    /* ------------------------------------------------------------------ projects */

    private static function listProjects(): Tool
    {
        return new Tool(
            'list_projects',
            'List projects',
            'List projects (jobs) with status, customer, team and task counts. Filter by status, customer or a name search.' . self::GLOSSARY,
            [
                'status' => S::enum('Only projects in this status.', self::STATUSES),
                'customer_id' => S::int('Only this customer\'s projects (user id).'),
                'search' => S::str('Part of the project name.', 100),
                'limit' => S::limit(40, 200),
            ],
            [],
            Tool::READ,
            function (array $a, ToolContext $c) {
                $res = $c->api->must($c->api->get('admin/project', array_filter(['customer_id' => $a['customer_id'] ?? null])));
                $rows = [];
                $byStatus = [];
                foreach ((array) $res as $status => $list) {
                    foreach ((array) $list as $p) {
                        $st = $p['status'] ?? $status;
                        $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;
                        if (!empty($a['status']) && strcasecmp($a['status'], (string) $st) !== 0) {
                            continue;
                        }
                        if (!empty($a['search']) && stripos((string) ($p['project_name'] ?? ''), $a['search']) === false) {
                            continue;
                        }
                        $rows[] = Present::clean([
                            'id' => $p['id'],
                            'name' => $p['project_name'] ?? null,
                            'status' => $st,
                            'customer' => self::person($p['customer'] ?? null),
                            'start_date' => $p['start_date'] ?? null,
                            'due_date' => $p['due_date'] ?? null,
                            'team' => self::names($p['project_assignees'] ?? []),
                            'top_level_tasks' => $p['tasks_count'] ?? null,
                            'urgent_tasks' => $p['urgent_count'] ?? null,
                        ]);
                    }
                }

                return ['projects_by_status_overall' => $byStatus] + Present::page($rows, $c->limit($a, 40, 200));
            }
        );
    }

    private static function getProject(): Tool
    {
        return new Tool(
            'get_project',
            'Get a project',
            'Full picture of one project: details, customer, team, task counts by status, its top-level tasks with assignees and logged hours, plus briefs and notes counts. Use list_tasks for deeper levels / filters.' . self::GLOSSARY,
            [
                'project_id' => S::int('Project id.', 1),
                'from' => S::date('Count logged hours only from this date (optional)'),
                'to' => S::date('Count logged hours only up to this date (optional)'),
            ],
            ['project_id'],
            Tool::READ,
            function (array $a, ToolContext $c) {
                $p = $c->api->must($c->api->get('admin/project/' . $a['project_id'], array_filter([
                    'summary_start_date' => $a['from'] ?? null,
                    'summary_end_date' => $a['to'] ?? null,
                ])));

                $hours = [];
                foreach ((array) ($p['tasks_hours_summary'] ?? []) as $h) {
                    $hours[(int) $h['task_id']] = $h['total_hours_formatted'] ?? null;
                }

                $statusCounts = [];
                $overdue = 0;
                $today = $c->today();
                foreach ((array) ($p['all_tasks'] ?? []) as $t) {
                    $s = $t['task_status'] ?? 'Unknown';
                    $statusCounts[$s] = ($statusCounts[$s] ?? 0) + 1;
                    if (!empty($t['due_date']) && $t['due_date'] < $today && strcasecmp((string) $s, 'Completed') !== 0) {
                        $overdue++;
                    }
                }

                $tasks = [];
                foreach ((array) ($p['tasks'] ?? []) as $t) {
                    $tasks[] = Present::clean([
                        'id' => $t['id'],
                        'title' => $t['task_title'] ?? null,
                        'status' => $t['task_status'] ?? null,
                        'priority' => $t['priority'] ?? null,
                        'due_date' => $t['due_date'] ?? null,
                        'assignees' => self::names($t['assignees'] ?? []),
                        'sub_tasks' => $t['sub_tasks_count'] ?? null,
                        'time_logged' => $hours[(int) $t['id']] ?? null,
                    ]);
                }

                return Present::clean([
                    'id' => $p['id'],
                    'name' => $p['project_name'] ?? null,
                    'description' => $p['project_description'] ?? null,
                    'status' => $p['status'] ?? null,
                    'start_date' => $p['start_date'] ?? null,
                    'due_date' => $p['due_date'] ?? null,
                    'delivered_date' => $p['delivered_date'] ?? null,
                    'customer' => self::person($p['customer'] ?? null),
                    'team' => self::names($p['project_assignees'] ?? []),
                    'tasks_total_all_levels' => count((array) ($p['all_tasks'] ?? [])),
                    'tasks_by_status' => $statusCounts,
                    'overdue_tasks' => $overdue,
                    'briefs' => count((array) ($p['all_briefs'] ?? [])),
                    'notes' => count((array) ($p['all_notes'] ?? [])),
                    'top_level_tasks' => Present::page($tasks, 100),
                ]);
            }
        );
    }

    private static function createProject(): Tool
    {
        return new Tool(
            'create_project',
            'Create a project',
            'Create a new project (job) and optionally put a team on it. Team members are notified (in-app + e-mail) exactly as when created in the portal.',
            [
                'name' => S::str('Project name.', 255),
                'description' => S::str('Description.'),
                'customer_id' => S::int('Customer (user id of a customer account).'),
                'start_date' => S::date('Start date'),
                'due_date' => S::date('Due date'),
                'status' => S::enum('Initial status (default: portal default).', self::STATUSES),
                'team_ids' => S::ids('Employee user ids to assign.'),
            ],
            ['name'],
            Tool::WRITE,
            function (array $a, ToolContext $c) {
                $body = array_filter([
                    'project_name' => $a['name'],
                    'project_description' => $a['description'] ?? null,
                    'customer_id' => $a['customer_id'] ?? null,
                    'start_date' => $a['start_date'] ?? null,
                    'due_date' => $a['due_date'] ?? null,
                    'status' => $a['status'] ?? null,
                ], fn ($v) => $v !== null);
                $body['employee_ids'] = array_values(array_unique($a['team_ids'] ?? []));

                $r = $c->api->must($c->api->post('admin/project', $body));

                return ['created' => true, 'project' => Present::clean(['id' => $r['project']['id'] ?? null, 'name' => $r['project']['project_name'] ?? $a['name'], 'status' => $r['project']['status'] ?? null])];
            }
        );
    }

    private static function updateProject(): Tool
    {
        return new Tool(
            'update_project',
            'Update project details',
            'Change a project\'s name, description, customer or dates. Only the fields you pass change. To change status use set_project_status; for the team use set_project_team.',
            [
                'project_id' => S::int('Project id.', 1),
                'name' => S::str('New name.', 255),
                'description' => S::str('New description.'),
                'customer_id' => S::int('New customer user id.'),
                'start_date' => S::date('Start date'),
                'due_date' => S::date('Due date'),
                'delivered_date' => S::date('Delivered date'),
            ],
            ['project_id'],
            Tool::WRITE,
            function (array $a, ToolContext $c) {
                $p = Project::find($a['project_id']);
                if (!$p) {
                    throw new ToolError('Project not found.');
                }
                // Send the current values for everything not being changed, so the portal's "due date changed"
                // notification only fires when the date really changes.
                $body = array_filter([
                    'project_name' => $a['name'] ?? $p->project_name,
                    'project_description' => $a['description'] ?? $p->project_description,
                    'customer_id' => $a['customer_id'] ?? $p->customer_id,
                    'start_date' => $a['start_date'] ?? $p->start_date,
                    'due_date' => $a['due_date'] ?? $p->due_date,
                    'delivered_date' => $a['delivered_date'] ?? $p->delivered_date,
                ], fn ($v) => $v !== null);

                $r = $c->api->must($c->api->put('admin/project/' . $p->id, $body));

                return ['updated' => true, 'project' => Present::clean($r['project'] ?? [])];
            }
        );
    }

    private static function setProjectStatus(): Tool
    {
        return new Tool(
            'set_project_status',
            'Set project status',
            'Change a project\'s status. Customers/team get the portal\'s normal status notification. CAUTION: setting "Completed" ALSO marks every task in the project Completed (that is how the portal works), so it needs confirm=true.',
            [
                'project_id' => S::int('Project id.', 1),
                'status' => S::enum('New status.', self::STATUSES),
                'confirm' => S::bool('Required (true) only when status is Completed - see description.'),
            ],
            ['project_id', 'status'],
            Tool::WRITE,
            function (array $a, ToolContext $c) {
                $p = Project::find($a['project_id']);
                if (!$p) {
                    throw new ToolError('Project not found.');
                }
                if ($a['status'] === 'Completed' && empty($a['confirm'])) {
                    $open = ProjectTask::where('project_id', $p->id)->where('task_status', '!=', 'Completed')->count();
                    throw new NeedsConfirm("Setting \"{$p->project_name}\" to Completed will also mark all {$open} of its not-yet-completed tasks (every level) as Completed and notify people. Tell the user, and call again with confirm=true if they agree.");
                }
                $before = $p->status;
                $c->api->must($c->api->post('admin/update-project-status', ['project_id' => $p->id, 'status' => $a['status']]));

                return ['updated' => true, 'project_id' => (int) $p->id, 'name' => $p->project_name, 'status_before' => $before, 'status_now' => $a['status']];
            }
        );
    }

    private static function setProjectTeam(): Tool
    {
        return new Tool(
            'set_project_team',
            'Change project team',
            'Add people to, remove people from, or replace the team (assignees) of a project. Newly added people are notified (push + in-app + e-mail).',
            [
                'project_id' => S::int('Project id.', 1),
                'mode' => S::enum('add = add these people, remove = take these people off, replace = the team becomes exactly this list.', ['add', 'remove', 'replace']),
                'user_ids' => S::ids('Employee user ids.'),
            ],
            ['project_id', 'mode', 'user_ids'],
            Tool::WRITE,
            function (array $a, ToolContext $c) {
                $p = Project::find($a['project_id']);
                if (!$p) {
                    throw new ToolError('Project not found.');
                }
                $known = User::whereIn('id', $a['user_ids'])->pluck('id')->map(fn ($v) => (int) $v)->all();
                $missing = array_values(array_diff($a['user_ids'], $known));
                if ($missing) {
                    throw new ToolError('Unknown user id(s): ' . implode(', ', $missing) . '.');
                }
                $current = ProjectAssignee::where('project_id', $p->id)->pluck('employee_id')->map(fn ($v) => (int) $v)->all();
                $next = match ($a['mode']) {
                    'add' => array_values(array_unique(array_merge($current, $a['user_ids']))),
                    'remove' => array_values(array_diff($current, $a['user_ids'])),
                    default => array_values(array_unique($a['user_ids'])),
                };
                $c->api->must($c->api->post('admin/update-project-assignees', ['project_id' => $p->id, 'employee_ids' => $next]));

                $team = User::whereIn('id', $next)->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all();

                return ['updated' => true, 'project' => $p->project_name, 'team_now' => $team];
            }
        );
    }

    /* ------------------------------------------------------------------ tasks */

    private static function listTasks(): Tool
    {
        return new Tool(
            'list_tasks',
            'List / search tasks',
            'Search tasks and sub-tasks at ANY level across all projects. Filter by project, parent task, status, priority, assignee, text, due dates, or only overdue. Newest due dates first within the filter; tasks without a due date last.' . self::GLOSSARY,
            [
                'project_id' => S::int('Only this project.'),
                'parent_task_id' => S::int('Only direct sub-tasks of this task.'),
                'top_level_only' => S::bool('Only top-level tasks (no sub-tasks).'),
                'status' => S::enum('Only this status.', self::STATUSES),
                'open_only' => S::bool('Hide Completed tasks.'),
                'priority' => S::enum('Only this priority.', self::PRIORITIES),
                'assignee_id' => S::int('Only tasks assigned to this user id.'),
                'search' => S::str('Part of the task title.', 100),
                'due_from' => S::date('Due on/after'),
                'due_to' => S::date('Due on/before'),
                'overdue_only' => S::bool('Only tasks past their due date and not Completed.'),
                'limit' => S::limit(40, 200),
            ],
            [],
            Tool::READ,
            function (array $a, ToolContext $c) {
                $q = ProjectTask::query();
                if (!empty($a['project_id'])) {
                    $q->where('project_id', $a['project_id']);
                }
                if (!empty($a['parent_task_id'])) {
                    $q->where('parent_task_id', $a['parent_task_id']);
                } elseif (!empty($a['top_level_only'])) {
                    $q->whereNull('parent_task_id');
                }
                if (!empty($a['status'])) {
                    $q->where('task_status', $a['status']);
                }
                if (!empty($a['open_only']) || !empty($a['overdue_only'])) {
                    $q->where('task_status', '!=', 'Completed');
                }
                if (!empty($a['priority'])) {
                    $q->where('priority', $a['priority']);
                }
                if (!empty($a['assignee_id'])) {
                    $q->whereIn('id', TaskAssignee::where('employee_id', $a['assignee_id'])->select('task_id'));
                }
                if (!empty($a['search'])) {
                    $q->where('task_title', 'like', self::like($a['search']));
                }
                if (!empty($a['due_from'])) {
                    $q->whereDate('due_date', '>=', $a['due_from']);
                }
                if (!empty($a['due_to'])) {
                    $q->whereDate('due_date', '<=', $a['due_to']);
                }
                if (!empty($a['overdue_only'])) {
                    $q->whereNotNull('due_date')->whereDate('due_date', '<', $c->today());
                }

                $total = (clone $q)->count();
                $limit = $c->limit($a, 40, 200);
                $rows = $q->with(['project:id,project_name', 'assignees.user:id,name'])->withCount('subTasks')
                    ->orderByRaw('due_date IS NULL')->orderBy('due_date')->orderByDesc('id')
                    ->limit($limit)->get()->map(fn ($t) => self::taskRow($t))->all();

                return Present::page($rows, $limit, $total);
            }
        );
    }

    private static function getTask(): Tool
    {
        return new Tool(
            'get_task',
            'Get a task',
            'Everything about one task: details, where it sits (project and parent tasks), assignees with the hours each has logged (including its sub-tasks), its direct sub-tasks, attachments, briefs and notes counts.',
            ['task_id' => S::int('Task id.', 1)],
            ['task_id'],
            Tool::READ,
            function (array $a, ToolContext $c) {
                $t = $c->api->must($c->api->get('admin/project-task/' . $a['task_id']));

                $crumbs = [];
                $pid = $t['parent_task_id'] ?? null;
                for ($i = 0; $pid && $i < 10; $i++) {
                    $parent = ProjectTask::find($pid);
                    if (!$parent) {
                        break;
                    }
                    array_unshift($crumbs, ['id' => (int) $parent->id, 'title' => $parent->task_title]);
                    $pid = $parent->parent_task_id;
                }
                $project = Project::find($t['project_id'] ?? 0);

                $hours = [];
                foreach ((array) ($t['assignees_with_hours'] ?? []) as $h) {
                    $hours[] = Present::clean([
                        'person' => self::person($h['user'] ?? null),
                        'time_logged' => $h['total_working_hours_formatted'] ?? null,
                        'no_longer_assigned' => !empty($h['removed_from_task']) ? true : null,
                    ]);
                }
                $subs = [];
                foreach ((array) ($t['sub_tasks'] ?? []) as $s) {
                    $subs[] = Present::clean([
                        'id' => $s['id'],
                        'title' => $s['task_title'] ?? null,
                        'status' => $s['task_status'] ?? null,
                        'priority' => $s['priority'] ?? null,
                        'due_date' => $s['due_date'] ?? null,
                        'assignees' => self::names($s['assignees'] ?? []),
                        'sub_tasks' => $s['sub_tasks_count'] ?? null,
                    ]);
                }

                return Present::clean([
                    'id' => $t['id'],
                    'title' => $t['task_title'] ?? null,
                    'description' => $t['task_description'] ?? null,
                    'status' => $t['task_status'] ?? null,
                    'priority' => $t['priority'] ?? null,
                    'due_date' => $t['due_date'] ?? null,
                    'completed_date' => $t['completed_date'] ?? null,
                    'project' => $project ? ['id' => (int) $project->id, 'name' => $project->project_name, 'status' => $project->status] : ['id' => $t['project_id'] ?? null],
                    'parent_tasks' => $crumbs,
                    'assignees_and_time_logged' => $hours,
                    'sub_tasks' => $subs,
                    'attachments' => array_map(fn ($f) => ['id' => $f['id'] ?? null, 'name' => $f['file_name'] ?? null, 'type' => $f['file_type'] ?? null, 'size_bytes' => $f['file_size'] ?? null], (array) ($t['attachments'] ?? [])),
                    'briefs' => count((array) ($t['all_briefs'] ?? [])),
                    'notes' => count((array) ($t['all_notes'] ?? [])),
                ]);
            }
        );
    }

    private static function createTask(): Tool
    {
        return new Tool(
            'create_task',
            'Create a task / sub-task',
            'Create a task in a project, or a sub-task under another task (pass parent_task_id). New top-level tasks are also assigned to the project\'s team (portal behaviour); add extra assignees with assignee_ids.',
            [
                'project_id' => S::int('Project id.', 1),
                'title' => S::str('Task title.', 255),
                'description' => S::str('Description.'),
                'status' => S::enum('Status (default Backlog).', self::STATUSES),
                'priority' => S::enum('Priority (default Normal).', self::PRIORITIES),
                'due_date' => S::date('Due date'),
                'parent_task_id' => S::int('Make it a sub-task of this task.'),
                'assignee_ids' => S::ids('Users to assign.'),
            ],
            ['project_id', 'title'],
            Tool::WRITE,
            function (array $a, ToolContext $c) {
                if (!empty($a['parent_task_id'])) {
                    $parent = ProjectTask::find($a['parent_task_id']);
                    if (!$parent) {
                        throw new ToolError('Parent task not found.');
                    }
                    if ((int) $parent->project_id !== (int) $a['project_id']) {
                        throw new ToolError("The parent task belongs to project #{$parent->project_id}, not #{$a['project_id']}.");
                    }
                }
                $body = array_filter([
                    'project_id' => $a['project_id'],
                    'task_title' => $a['title'],
                    'task_description' => $a['description'] ?? null,
                    'task_status' => $a['status'] ?? null,
                    'priority' => $a['priority'] ?? null,
                    'due_date' => $a['due_date'] ?? null,
                    'parent_task_id' => $a['parent_task_id'] ?? null,
                ], fn ($v) => $v !== null);
                if (!empty($a['assignee_ids'])) {
                    $body['employee_ids'] = array_values(array_unique($a['assignee_ids']));
                }
                $r = $c->api->must($c->api->post('admin/project-task', $body));

                return ['created' => true, 'task' => Present::clean(['id' => $r['task']['id'] ?? null, 'title' => $r['task']['task_title'] ?? $a['title'], 'status' => $r['task']['task_status'] ?? null, 'priority' => $r['task']['priority'] ?? null, 'parent_task_id' => $r['task']['parent_task_id'] ?? null])];
            }
        );
    }

    private static function updateTask(): Tool
    {
        return new Tool(
            'update_task',
            'Update a task',
            'Change a task\'s title, description, status, priority or due date. Only the fields you pass change. Status changes notify the assignees as in the portal, and the project\'s own status is recalculated from its tasks (portal rule). To change who is assigned use set_task_assignees.',
            [
                'task_id' => S::int('Task id.', 1),
                'title' => S::str('New title.', 255),
                'description' => S::str('New description.'),
                'status' => S::enum('New status.', self::STATUSES),
                'priority' => S::enum('New priority.', self::PRIORITIES),
                'due_date' => S::date('New due date'),
            ],
            ['task_id'],
            Tool::WRITE,
            function (array $a, ToolContext $c) {
                $t = ProjectTask::find($a['task_id']);
                if (!$t) {
                    throw new ToolError('Task not found.');
                }
                $changes = array_intersect_key($a, array_flip(['title', 'description', 'status', 'priority', 'due_date']));
                if (!$changes) {
                    throw new ToolError('Nothing to change - pass at least one of title, description, status, priority, due_date.');
                }
                // Current values go along for everything that is NOT changing, so the portal's "status changed" and
                // "due date changed" notifications fire only for real changes.
                $body = array_filter([
                    'task_title' => $a['title'] ?? $t->task_title,
                    'task_description' => $a['description'] ?? $t->task_description,
                    'task_status' => $a['status'] ?? $t->task_status,
                    'priority' => $a['priority'] ?? $t->priority,
                    'due_date' => $a['due_date'] ?? $t->due_date,
                ], fn ($v) => $v !== null);
                $before = ['status' => $t->task_status, 'priority' => $t->priority, 'due_date' => $t->due_date, 'title' => $t->task_title];

                $c->api->must($c->api->put('admin/project-task/' . $t->id, $body));
                $t->refresh();

                return ['updated' => true, 'task' => self::taskRow($t), 'before' => Present::clean(array_intersect_key($before, $changes))];
            }
        );
    }

    private static function setTaskAssignees(): Tool
    {
        return new Tool(
            'set_task_assignees',
            'Change task assignees',
            'Add people to, remove people from, or replace the assignees of a task or sub-task. Newly added people are notified (push + in-app + e-mail).',
            [
                'task_id' => S::int('Task id.', 1),
                'mode' => S::enum('add = add these people, remove = unassign these people, replace = the assignees become exactly this list.', ['add', 'remove', 'replace']),
                'user_ids' => S::ids('User ids.'),
            ],
            ['task_id', 'mode', 'user_ids'],
            Tool::WRITE,
            function (array $a, ToolContext $c) {
                $t = ProjectTask::find($a['task_id']);
                if (!$t) {
                    throw new ToolError('Task not found.');
                }
                $known = User::whereIn('id', $a['user_ids'])->pluck('id')->map(fn ($v) => (int) $v)->all();
                $missing = array_values(array_diff($a['user_ids'], $known));
                if ($missing) {
                    throw new ToolError('Unknown user id(s): ' . implode(', ', $missing) . '.');
                }
                $current = TaskAssignee::where('task_id', $t->id)->pluck('employee_id')->map(fn ($v) => (int) $v)->all();
                $next = match ($a['mode']) {
                    'add' => array_values(array_unique(array_merge($current, $a['user_ids']))),
                    'remove' => array_values(array_diff($current, $a['user_ids'])),
                    default => array_values(array_unique($a['user_ids'])),
                };
                $c->api->must($c->api->post('admin/bulk-assign', ['task_id' => $t->id, 'employee_ids' => $next]));

                $now = User::whereIn('id', $next)->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all();

                return ['updated' => true, 'task' => ['id' => (int) $t->id, 'title' => $t->task_title], 'assignees_now' => $now];
            }
        );
    }

    /* ------------------------------------------------------------------ comments & project chat */

    private static function comment(array $m): array
    {
        $replies = [];
        foreach ((array) ($m['replies'] ?? []) as $r) {
            $replies[] = self::comment($r + ['replies' => []]);
        }

        return Present::clean([
            'id' => $m['id'] ?? null,
            'from' => self::person($m['sender'] ?? null),
            'text' => $m['comment_message'] ?? null,
            'at' => $m['created_at'] ?? null,
            'reply_to' => $m['reply_to'] ?? null,
            'pinned' => !empty($m['is_pinned']) ? true : null,
            'read_by_me' => array_key_exists('is_read', $m) ? (bool) $m['is_read'] : null,
            'files' => array_map(fn ($f) => $f['file_name'] ?? null, (array) ($m['comment_attachments'] ?? [])),
            'replies' => $replies,
        ]);
    }

    private static function listTaskComments(): Tool
    {
        return new Tool(
            'list_task_comments',
            'Read task comments',
            'Read the discussion on a task, with replies. audience "internal" (default) = the team-only thread; "customer" = the thread the customer can see. Pages of 10 top-level comments, oldest to newest within a page; page 1 is the most recent.',
            [
                'task_id' => S::int('Task id.', 1),
                'audience' => S::enum('Which thread.', ['internal', 'customer']),
                'page' => S::int('Page (1 = newest).', 1),
            ],
            ['task_id'],
            Tool::READ,
            function (array $a, ToolContext $c) {
                $path = ($a['audience'] ?? 'internal') === 'customer' ? 'admin/task-comment-with-customers' : 'admin/task-comment';
                $r = $c->api->must($c->api->get($path, ['task_id' => $a['task_id'], 'page' => $a['page'] ?? 1]));

                return [
                    'page' => $r['current_page'] ?? 1,
                    'last_page' => $r['last_page'] ?? 1,
                    'total' => $r['total'] ?? null,
                    'comments' => array_map(fn ($m) => self::comment($m), (array) ($r['data'] ?? [])),
                ];
            }
        );
    }

    private static function addTaskComment(): Tool
    {
        return new Tool(
            'add_task_comment',
            'Comment on a task',
            'Post a comment (or a reply) on a task as the signed-in admin. Assignees, admins and tagged people are notified by in-app message and e-mail like in the portal. Set visible_to_customer=true ONLY if the customer should see it.',
            [
                'task_id' => S::int('Task id.', 1),
                'message' => S::str('The comment text.', 20000),
                'reply_to_comment_id' => S::int('Reply to this comment id.'),
                'tag_user_ids' => S::ids('Users to @mention / notify.'),
                'visible_to_customer' => S::bool('true = the customer can read it (default false = team only).'),
            ],
            ['task_id', 'message'],
            Tool::WRITE,
            function (array $a, ToolContext $c) {
                $body = array_filter([
                    'task_id' => $a['task_id'],
                    'comment_message' => $a['message'],
                    'reply_to' => $a['reply_to_comment_id'] ?? null,
                    'allowed_customer' => !empty($a['visible_to_customer']) ? 1 : 0,
                ], fn ($v) => $v !== null);
                if (!empty($a['tag_user_ids'])) {
                    $body['tag_user'] = array_values(array_unique($a['tag_user_ids']));
                }
                $r = $c->api->must($c->api->post('admin/task-comment', $body));

                return ['posted' => true, 'comment_id' => $r['comment']['id'] ?? null, 'visible_to_customer' => !empty($a['visible_to_customer'])];
            }
        );
    }

    private static function projectChatMessages(): Tool
    {
        return new Tool(
            'project_chat_messages',
            'Read a project\'s chat',
            'Read the project-level chat (not the per-task comments). audience "internal" = team-only (default), "customer" = the thread shared with the customer.',
            [
                'project_id' => S::int('Project id.', 1),
                'audience' => S::enum('Which thread.', ['internal', 'customer']),
                'limit' => S::limit(40, 200),
            ],
            ['project_id'],
            Tool::READ,
            function (array $a, ToolContext $c) {
                $path = ($a['audience'] ?? 'internal') === 'customer' ? 'admin/project-chat-with-customers/' : 'admin/project-chat/';
                $r = $c->api->must($c->api->get($path . $a['project_id']));
                $rows = array_map(fn ($m) => Present::clean([
                    'id' => $m['id'] ?? null,
                    'from' => self::person($m['sender'] ?? null),
                    'is_admin' => !empty($m['is_admin']) ? true : null,
                    'text' => $m['message'] ?? null,
                    'at' => $m['created_at'] ?? null,
                    'files' => array_map(fn ($f) => $f['file_name'] ?? null, (array) ($m['attachments'] ?? [])),
                ]), (array) ($r['chats'] ?? []));
                $limit = $c->limit($a, 40, 200);
                $total = count($rows);

                // newest messages are the useful ones
                return ['total' => $total, 'shown' => min($limit, $total), 'messages' => array_slice($rows, -$limit)];
            }
        );
    }

    private static function projectChatSend(): Tool
    {
        return new Tool(
            'project_chat_send',
            'Post in a project\'s chat',
            'Post a message in the project-level chat as the signed-in admin. visible_to_customer=true puts it in the thread the customer can read - use only when the user wants the customer to see it.',
            [
                'project_id' => S::int('Project id.', 1),
                'message' => S::str('Message text.', 20000),
                'visible_to_customer' => S::bool('true = customer can read it (default false = team only).'),
            ],
            ['project_id', 'message'],
            Tool::WRITE,
            function (array $a, ToolContext $c) {
                $r = $c->api->must($c->api->post('admin/project-chat', [
                    'project_id' => $a['project_id'],
                    'message' => $a['message'],
                    'allowed_customer' => !empty($a['visible_to_customer']) ? 1 : 0,
                ]));

                return ['posted' => true, 'message_id' => $r['chat']['id'] ?? ($r['data']['id'] ?? null), 'visible_to_customer' => !empty($a['visible_to_customer'])];
            }
        );
    }
}
