<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ChatInsights;
use App\Support\ChatSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * "#" tags in chat messages: search the tasks and projects a person may tag, and the small summary the chat's
 * side panel shows for a tagged task or project. Read-only - it never changes any task data.
 *
 * Who can see what: admins (and supervisors) see everything; everybody else sees the projects they are assigned
 * to, the tasks inside those projects, and tasks they are personally assigned to or created.
 */
class ChatTagController extends Controller
{
    private function unsupported()
    {
        return response()->json(['supported' => false], 503);
    }

    private function seesAll($user): bool
    {
        return in_array((int) $user->user_role, [1, 2, 6], true);
    }

    /** @return array{0:\Illuminate\Support\Collection,1:\Illuminate\Support\Collection} [projectIds, taskIds] this person may see; both null-ish when they see all. */
    private function scope($user): array
    {
        $projects = DB::table('project_assignees')->where('employee_id', $user->id)->pluck('project_id');
        $tasks = DB::table('task_assignees')->where('employee_id', $user->id)->pluck('task_id')
            ->merge(DB::table('project_tasks')->where('created_by', $user->id)->pluck('id'));
        // A task assignment also opens its project.
        $projects = $projects->merge(DB::table('project_tasks')->whereIn('id', $tasks)->pluck('project_id'))->unique()->values();

        return [$projects, $tasks->unique()->values()];
    }

    private function canSeeProject($user, int $projectId): bool
    {
        if ($this->seesAll($user)) {
            return true;
        }

        return $this->scope($user)[0]->contains($projectId);
    }

    /** GET /api/chat/v2/tags/search?q= */
    public function search(Request $request)
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }

        $user = Auth::user();
        $q = trim((string) $request->query('q', ''));
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $all = $this->seesAll($user);
        [$projectIds] = $all ? [collect()] : $this->scope($user);

        $tasks = DB::table('project_tasks as t')
            ->join('projects as p', 'p.id', '=', 't.project_id')
            ->whereNull('t.deleted_at')->whereNull('p.deleted_at')
            ->when($q !== '', fn ($x) => $x->where('t.task_title', 'like', $like))
            ->when(!$all, fn ($x) => $x->whereIn('t.project_id', $projectIds))
            ->orderByDesc('t.updated_at')->limit(8)
            ->get(['t.id', 't.task_title', 't.task_status', 'p.project_name']);

        $projects = DB::table('projects as p')
            ->whereNull('p.deleted_at')
            ->when($q !== '', fn ($x) => $x->where('p.project_name', 'like', $like))
            ->when(!$all, fn ($x) => $x->whereIn('p.id', $projectIds))
            ->orderByDesc('p.updated_at')->limit($q === '' ? 4 : 6)
            ->get(['p.id', 'p.project_name', 'p.status']);

        $results = [];
        foreach ($projects as $p) {
            $results[] = ['type' => 'project', 'id' => (int) $p->id, 'title' => (string) $p->project_name, 'subtitle' => 'Project', 'status' => $p->status];
        }
        foreach ($tasks as $t) {
            $results[] = ['type' => 'task', 'id' => (int) $t->id, 'title' => (string) $t->task_title, 'subtitle' => (string) $t->project_name, 'status' => $t->task_status];
        }

        return response()->json(['results' => $results]);
    }

    /** GET /api/chat/v2/tags/{type}/{id} */
    public function show($type, $id, ChatInsights $insights)
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }
        if (!in_array($type, ['task', 'project'], true)) {
            abort(404);
        }

        $user = Auth::user();
        $plain = fn ($html) => ($t = trim(preg_replace('/\s+/', ' ', strip_tags((string) $html)))) === '' ? null : (mb_strlen($t) > 280 ? mb_substr($t, 0, 280) . '…' : $t);
        $people = function ($ids) {
            return DB::table('users')->whereIn('id', $ids)->get(['id', 'name', 'profile_pic'])->keyBy('id');
        };

        if ($type === 'task') {
            $t = DB::table('project_tasks as t')->join('projects as p', 'p.id', '=', 't.project_id')
                ->where('t.id', $id)->whereNull('t.deleted_at')->whereNull('p.deleted_at')
                ->first(['t.*', 'p.project_name', 'p.status as project_status']);
            if (!$t) {
                return response()->json(['message' => 'This task no longer exists.', 'gone' => true], 404);
            }
            if (!$this->canSeeProject($user, (int) $t->project_id)) {
                return response()->json(['message' => 'You do not have access to this task.', 'forbidden' => true], 403);
            }

            $assigneeIds = DB::table('task_assignees')->where('task_id', $t->id)->pluck('employee_id');
            $subs = DB::table('project_tasks')->where('parent_task_id', $t->id)->whereNull('deleted_at')->get(['task_status']);
            $parent = $t->parent_task_id ? DB::table('project_tasks')->where('id', $t->parent_task_id)->value('task_title') : null;
            $time = $insights->taskTotals([(int) $t->id], (int) $user->id);
            $users = $people($assigneeIds->merge(array_keys($time['by_user'])));

            return response()->json([
                'type' => 'task',
                'id' => (int) $t->id,
                'title' => $t->task_title,
                'status' => $t->task_status,
                'priority' => $t->priority,
                'due_date' => $t->due_date,
                'completed_date' => $t->completed_date,
                'description' => $plain($t->task_description),
                'project' => ['id' => (int) $t->project_id, 'name' => $t->project_name, 'status' => $t->project_status],
                'parent_title' => $parent,
                'subtasks' => ['total' => $subs->count(), 'done' => $subs->where('task_status', 'Completed')->count()],
                'assignees' => $assigneeIds->map(fn ($uid) => $users->get($uid))->filter()->values()->map(fn ($u) => ['id' => (int) $u->id, 'name' => $u->name, 'profile_pic' => $u->profile_pic])->all(),
                'time' => ['total_seconds' => $time['total'], 'mine_seconds' => $time['mine'], 'last_worked_at' => $time['last'],
                    'top' => collect($time['by_user'])->take(4)->map(fn ($secs, $uid) => ['name' => $users->get($uid)->name ?? 'Someone', 'seconds' => $secs])->values()->all()],
                'updated_at' => $t->updated_at,
                'path' => '/jobs/task/' . $t->id,
            ]);
        }

        $p = DB::table('projects')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$p) {
            return response()->json(['message' => 'This project no longer exists.', 'gone' => true], 404);
        }
        if (!$this->canSeeProject($user, (int) $p->id)) {
            return response()->json(['message' => 'You do not have access to this project.', 'forbidden' => true], 403);
        }

        $tasks = DB::table('project_tasks')->where('project_id', $p->id)->whereNull('deleted_at')->get(['id', 'task_title', 'task_status', 'due_date', 'updated_at']);
        $byStatus = $tasks->groupBy('task_status')->map->count()->all();
        $assigneeIds = DB::table('project_assignees')->where('project_id', $p->id)->pluck('employee_id');
        $time = $insights->taskTotals($tasks->pluck('id')->map(fn ($v) => (int) $v)->all(), (int) $user->id);
        $users = $people($assigneeIds->merge(array_keys($time['by_user'])));

        return response()->json([
            'type' => 'project',
            'id' => (int) $p->id,
            'title' => $p->project_name,
            'status' => $p->status,
            'description' => $plain($p->project_description),
            'start_date' => $p->start_date,
            'due_date' => $p->due_date,
            'delivered_date' => $p->delivered_date,
            'tasks' => ['total' => $tasks->count(), 'done' => $tasks->where('task_status', 'Completed')->count(), 'by_status' => $byStatus],
            'open_tasks' => $tasks->where('task_status', '!=', 'Completed')->sortByDesc('updated_at')->take(5)->map(fn ($t) => ['id' => (int) $t->id, 'title' => $t->task_title, 'status' => $t->task_status, 'due_date' => $t->due_date])->values()->all(),
            'assignees' => $assigneeIds->map(fn ($uid) => $users->get($uid))->filter()->values()->map(fn ($u) => ['id' => (int) $u->id, 'name' => $u->name, 'profile_pic' => $u->profile_pic])->all(),
            'time' => ['total_seconds' => $time['total'], 'mine_seconds' => $time['mine'], 'last_worked_at' => $time['last'],
                'top' => collect($time['by_user'])->take(4)->map(fn ($secs, $uid) => ['name' => $users->get($uid)->name ?? 'Someone', 'seconds' => $secs])->values()->all()],
            'updated_at' => $p->updated_at,
            'path' => '/jobs/' . $p->id,
        ]);
    }
}
