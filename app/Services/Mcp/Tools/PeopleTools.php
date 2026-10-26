<?php

namespace App\Services\Mcp\Tools;

use App\Models\ProjectAssignee;
use App\Models\ProjectTask;
use App\Models\TaskAssignee;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Mcp\Present;
use App\Services\Mcp\S;
use App\Services\Mcp\Tool;
use App\Services\Mcp\ToolContext;
use App\Services\Mcp\ToolError;
use Carbon\Carbon;

/** People: find anyone, list staff/customers, one person's workload, who is working right now. */
final class PeopleTools
{
    public const ROLES = [2 => 'admin', 3 => 'employee', 4 => 'customer', 5 => 'customer team member', 6 => 'supervisor', 7 => 'executive'];

    /** @return Tool[] */
    public static function all(): array
    {
        return [self::findPeople(), self::listUsers(), self::getUser(), self::whoIsWorking()];
    }

    public static function roleName(mixed $role): string
    {
        return self::ROLES[(int) $role] ?? 'unknown';
    }

    private static function like(string $term): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($term)) . '%';
    }

    private static function userRow(array|User $u): array
    {
        $u = $u instanceof User ? $u->toArray() : $u;

        return Present::clean([
            'id' => $u['id'] ?? null,
            'name' => $u['name'] ?? null,
            'role' => self::roleName($u['user_role'] ?? null),
            'employee_type' => $u['employee_type'] ?? null,
            'team' => $u['employee_team'] ?? null,
            'email' => $u['email'] ?? null,
            'phone' => $u['phone'] ?? null,
            'joined' => $u['joining_date'] ?? null,
        ]);
    }

    private static function findPeople(): Tool
    {
        return new Tool(
            'find_people',
            'Find people by name',
            'Look someone up by name, e-mail, username or phone, across ALL account types (admins, employees, customers, team members). Use this first whenever you need a user id for another tool.',
            [
                'query' => S::str('Part of a name, e-mail, username or phone.', 100),
                'role' => S::enum('Limit to one account type.', ['admin', 'employee', 'customer', 'customer_team_member', 'supervisor']),
                'limit' => S::limit(15, 50),
            ],
            ['query'],
            Tool::READ,
            function (array $a, ToolContext $c) {
                $q = User::query();
                $like = self::like($a['query']);
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('username', 'like', $like)->orWhere('phone', 'like', $like));
                if (!empty($a['role'])) {
                    $q->where('user_role', ['admin' => 2, 'employee' => 3, 'customer' => 4, 'customer_team_member' => 5, 'supervisor' => 6][$a['role']]);
                }
                $total = (clone $q)->count();
                $limit = $c->limit($a, 15, 50);
                $rows = $q->orderBy('name')->limit($limit)->get()->map(fn ($u) => self::userRow($u))->all();

                return Present::page($rows, $limit, $total);
            }
        );
    }

    private static function listUsers(): Tool
    {
        return new Tool(
            'list_users',
            'List staff or customers',
            'List accounts of one type. For employees it also shows whether their timer is running and the time worked today and this week. Optional filters: search text, employee type (Employee, Manager, Executive, Internee, Outsource, Supervisor), team.',
            [
                'type' => S::enum('Account type (default employee).', ['employee', 'customer', 'supervisor', 'admin']),
                'search' => S::str('Part of name / e-mail / username / phone.', 100),
                'employee_type' => S::str('Employees only: e.g. Manager, Executive, Internee, Outsource, Employee.', 40),
                'employee_team' => S::str('Employees only: team name, e.g. "BIM Team".', 60),
                'limit' => S::limit(40, 200),
            ],
            [],
            Tool::READ,
            function (array $a, ToolContext $c) {
                $type = $a['type'] ?? 'employee';
                $limit = $c->limit($a, 40, 200);
                $r = $c->api->must($c->api->get("admin/{$type}-user", array_filter([
                    'search' => $a['search'] ?? null,
                    'employee_type' => $type === 'employee' ? ($a['employee_type'] ?? null) : null,
                    'employee_team' => $type === 'employee' ? ($a['employee_team'] ?? null) : null,
                    'per_page' => $limit,
                    'page' => 1,
                ])));

                $rows = array_map(function ($u) {
                    return Present::clean([
                        'id' => $u['id'] ?? null,
                        'name' => $u['name'] ?? null,
                        'employee_type' => $u['employee_type'] ?? null,
                        'team' => $u['employee_team'] ?? null,
                        'email' => $u['email'] ?? null,
                        'phone' => $u['phone'] ?? null,
                        'joined' => $u['joining_date'] ?? null,
                        'manager_id' => $u['manager_id'] ?? null,
                        'timer' => $u['timer_status'] ?? null,
                        'worked_today' => $u['today_time'] ?? null,
                        'worked_this_week' => $u['week_time'] ?? null,
                    ]);
                }, (array) ($r['data'] ?? []));

                return Present::page($rows, $limit, $r['total'] ?? count($rows));
            }
        );
    }

    private static function getUser(): Tool
    {
        return new Tool(
            'get_user',
            'Get one person',
            'Profile and current workload of one person: contact details, role/type/team, reporting manager, joining date, projects they are on, how many open tasks they have (and how many are overdue), pending leave requests, and whether their timer is running.',
            ['user_id' => S::int('User id.', 1)],
            ['user_id'],
            Tool::READ,
            function (array $a, ToolContext $c) {
                $u = User::withTrashed()->find($a['user_id']);
                if (!$u) {
                    throw new ToolError('Person not found.');
                }
                $manager = $u->manager_id ? User::withTrashed()->find($u->manager_id) : null;

                $projectIds = ProjectAssignee::where('employee_id', $u->id)->pluck('project_id')->all();
                $projects = $projectIds ? \App\Models\Project::whereIn('id', $projectIds)->get(['id', 'project_name', 'status']) : collect();

                $openTasks = ProjectTask::whereIn('id', TaskAssignee::where('employee_id', $u->id)->select('task_id'))->where('task_status', '!=', 'Completed');
                $open = (clone $openTasks)->count();
                $overdue = (clone $openTasks)->whereNotNull('due_date')->whereDate('due_date', '<', $c->today())->count();

                $pendingLeaves = \App\Models\LeaveRequest::where('user_id', $u->id)->where('status', 'Pending')->count();
                $running = WorkSession::where('user_id', $u->id)->whereNull('end_time')->orderByDesc('id')->first();

                return Present::clean([
                    'id' => (int) $u->id,
                    'name' => $u->name,
                    'role' => self::roleName($u->user_role),
                    'employee_type' => $u->employee_type,
                    'team' => $u->employee_team,
                    'email' => $u->email,
                    'username' => $u->username,
                    'phone' => $u->phone,
                    'gender' => $u->gender ?? null,
                    'joined' => $u->joining_date,
                    'probation_ends' => $u->probation_period_end_date ?? null,
                    'reports_to' => $manager ? ['id' => (int) $manager->id, 'name' => $manager->name] : null,
                    'deleted' => $u->trashed() ? true : null,
                    'on_projects' => $projects->map(fn ($p) => ['id' => (int) $p->id, 'name' => $p->project_name, 'status' => $p->status])->all(),
                    'open_tasks' => $open,
                    'overdue_tasks' => $overdue,
                    'pending_leave_requests' => $pendingLeaves,
                    'timer_running_since' => $running ? trim($running->start_date . ' ' . $running->start_time) : null,
                ]);
            }
        );
    }

    private static function whoIsWorking(): Tool
    {
        return new Tool(
            'who_is_working_now',
            'Who is working right now',
            'Everyone whose time tracker is running at this moment (a timer left running since before yesterday is treated as forgotten and not listed), with the task and project and how long it has been running.',
            ['limit' => S::limit(60, 200)],
            [],
            Tool::READ,
            function (array $a, ToolContext $c) {
                $limit = $c->limit($a, 60, 200);
                // A timer that has been "running" since before yesterday was never stopped (the portal closes these
                // automatically later), so it is not counted as working right now.
                $q = WorkSession::whereNull('end_time')->whereNull('end_date')->whereDate('start_date', '>=', Carbon::now()->subDay()->toDateString());
                $total = (clone $q)->count();
                $rows = $q->with(['userDetail:id,name,employee_type,employee_team', 'taskDetail:id,task_title,project_id'])
                    ->orderBy('start_date')->orderBy('start_time')->limit($limit)->get();
                $projects = \App\Models\Project::whereIn('id', $rows->pluck('taskDetail.project_id')->filter()->unique())->pluck('project_name', 'id');

                $now = Carbon::now();
                $out = $rows->map(function ($s) use ($now, $projects) {
                    try {
                        $started = Carbon::parse($s->start_date . ' ' . $s->start_time);
                        $mins = max(0, (int) abs($now->diffInMinutes($started)));
                    } catch (\Throwable $e) {
                        $started = null;
                        $mins = null;
                    }
                    $task = $s->taskDetail;

                    return Present::clean([
                        'person' => ['id' => $s->user_id, 'name' => $s->userDetail->name ?? null],
                        'team' => $s->userDetail->employee_team ?? null,
                        'task' => $task ? ['id' => (int) $task->id, 'title' => $task->task_title] : null,
                        'project' => $task ? ($projects[$task->project_id] ?? null) : null,
                        'started' => $started ? $started->format('Y-m-d H:i') : null,
                        'running_for' => $mins === null ? null : Present::hm($mins * 60),
                        'entry_type' => $s->type,
                    ]);
                })->all();

                return Present::page($out, $limit, $total);
            }
        );
    }
}
