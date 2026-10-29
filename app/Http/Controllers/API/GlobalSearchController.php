<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * GET /api/search?q=  -  one search box for jobs and tasks (used by the mobile app's dashboard search).
 *
 * Read-only and additive. Who sees what mirrors the rest of the portal:
 *  - admins (2) and staff (3, 6, 7): every job and task, like the job lists they already have;
 *  - customers (4): only the jobs that belong to them, and the tasks inside those jobs;
 *  - anyone else (e.g. customer team members): nothing here (they keep using their own screens).
 * Every query is bounded (short text, small limits) so it can never become a heavy request.
 */
class GlobalSearchController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['query' => $q, 'jobs' => [], 'tasks' => []]);
        }
        $q = mb_substr($q, 0, 80);
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

        $role = (int) ($user->user_role ?? 0);
        $staff = in_array($role, [2, 3, 6, 7], true);
        if (!$staff && $role !== 4) {
            return response()->json(['query' => $q, 'jobs' => [], 'tasks' => []]);
        }

        $jobQuery = Project::query()
            ->where(function ($w) use ($like) {
                $w->where('project_name', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like));
            })
            ->with('customer:id,name')
            ->orderByDesc('id')
            ->limit(10);

        $taskQuery = ProjectTask::query()
            ->where('task_title', 'like', $like)
            ->with(['project:id,project_name,customer_id'])
            ->orderByDesc('id')
            ->limit(20);

        if ($role === 4) {
            $jobQuery->where('customer_id', $user->id);
            $taskQuery->whereHas('project', fn ($p) => $p->where('customer_id', $user->id));
        }

        $jobs = $jobQuery->get()->map(fn ($p) => [
            'id' => (int) $p->id,
            'name' => $p->project_name,
            'status' => $p->status,
            'customer' => $p->customer?->name,
        ])->values();

        $tasks = $taskQuery->get()->map(fn ($t) => [
            'id' => (int) $t->id,
            'title' => $t->task_title,
            'status' => $t->task_status,
            'priority' => $t->priority,
            'due_date' => $t->due_date ? substr((string) $t->due_date, 0, 10) : null,
            'is_sub_task' => !empty($t->parent_task_id),
            'job_id' => $t->project ? (int) $t->project->id : null,
            'job' => $t->project?->project_name,
        ])->values();

        return response()->json(['query' => $q, 'jobs' => $jobs, 'tasks' => $tasks]);
    }
}
