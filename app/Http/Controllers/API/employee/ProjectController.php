<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use App\Traits\CalculatesIdleTime;
use App\Traits\AnnotatesTaskUrgency;
use App\Traits\ResolvesTaskTree;
use Illuminate\Http\Request;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectAssignee;
use Auth;
use App\Models\WorkSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class ProjectController extends Controller
{
    use AnnotatesTaskUrgency, CalculatesIdleTime, ResolvesTaskTree;

    public function index(Request $request)
    {
        $user = Auth::user();

        // Electron time tracker — slim paginated response, no heavy relations
        $userAgent = $request->header('User-Agent', '');
        if (strpos($userAgent, 'Electron') !== false) {
            return $this->indexForElectron($request, $user);
        }

        if($user->employee_type === "Internee"){
            return $this->interneeProjects($request, $user);
        }

        $statusOrder = [
            'On Hold' => 1,
            'Backlog' => 2,
            'Awaiting Info' => 3,
            'In Progress' => 4,
            'In-house review' => 5,
            'Client Review' => 6,
            'Completed' => 7,
        ];

        $withRelations = [
            'customer:id,name,profile_pic',
            'projectAssignees:id,employee_id,project_id',
            'projectAssignees.user:id,name,profile_pic',
        ];

        if (
            ($user->employee_type == "Manager" || $user->employee_type == "Supervisor" || $user->employee_type == "Executive")
            && !$request->boolean('assigned_me')
        ) {
            $projects = Project::latest()
                ->with($withRelations)->withCount(['tasks', 'allTasks as urgent_count' => function ($q) { $q->where('priority', 'Urgent'); }])
                ->when($request->customer_id, function ($query) use ($request) {
                    $query->where('customer_id', $request->customer_id);
                })
                ->get();
        } else {
            $userId = $user->id;

            // Single join query instead of 3 sequential queries
            $linkedProjectIds = ProjectAssignee::where('employee_id', $userId)
                ->pluck('project_id');

            $taskProjectIds = DB::table('task_assignees')
                ->join('project_tasks', 'task_assignees.task_id', '=', 'project_tasks.id')
                ->where('task_assignees.employee_id', $userId)
                ->whereNull('project_tasks.deleted_at')
                ->pluck('project_tasks.project_id');

            $all_project_ids = $linkedProjectIds->merge($taskProjectIds)->unique()->values()->toArray();

            $projects = Project::latest()
                ->with($withRelations)->withCount(['tasks', 'allTasks as urgent_count' => function ($q) { $q->where('priority', 'Urgent'); }])
                ->whereIn('id', $all_project_ids)
                ->when($request->customer_id, function ($query) use ($request) {
                    $query->where('customer_id', $request->customer_id);
                })
                ->get();
        }

        // ✅ Group by status
        $grouped = [];
        foreach ($projects as $project) {
            $status = $project->status ?? 'Unknown';
            $grouped[$status][] = $project;
        }

        // ✅ Sort groups based on $statusOrder
        uksort($grouped, function ($a, $b) use ($statusOrder) {
            $orderA = $statusOrder[$a] ?? 999;
            $orderB = $statusOrder[$b] ?? 999;
            return $orderA <=> $orderB;
        });

        $userAgent = $request->header('User-Agent');
        if (strpos($userAgent, 'Electron') !== false) {
            return response()->json($projects); // old structure
        }

        return response()->json($grouped);
    }

    public function interneeProjects(Request $request, $user){

         $withRelations = [
            'customer:id,name,profile_pic',
            'projectAssignees:id,employee_id,project_id',
            'projectAssignees.user:id,name,profile_pic',
        ];

        $userId = $user->id;

        // Single join query instead of 3 sequential queries
        $linkedProjectIds = ProjectAssignee::where('employee_id', $userId)
            ->pluck('project_id');

        $taskProjectIds = DB::table('task_assignees')
            ->join('project_tasks', 'task_assignees.task_id', '=', 'project_tasks.id')
            ->where('task_assignees.employee_id', $userId)
            ->whereNull('project_tasks.deleted_at')
            ->pluck('project_tasks.project_id');

        $all_project_ids = $linkedProjectIds->merge($taskProjectIds)->unique()->values()->toArray();

        $projects = Project::latest()
                ->with($withRelations)->withCount(['tasks', 'allTasks as urgent_count' => function ($q) { $q->where('priority', 'Urgent'); }])
                ->whereIn('id', $all_project_ids)
                ->when($request->customer_id, function ($query) use ($request) {
                    $query->where('customer_id', $request->customer_id);
                })
                ->get();

        return response()->json($projects);
    }


    private function indexForElectron(Request $request, $user)
    {
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = 20;
        $search  = trim($request->input('search', ''));

        $isPrivileged = (
            $user->employee_type == 'Manager' ||
            $user->employee_type == 'Supervisor' ||
            $user->employee_type == 'Executive'
        ) && !$request->boolean('assigned_me');

        $query = Project::select(['id', 'project_name', 'status', 'project_description', 'created_at'])
            ->latest();

        if (!$isPrivileged) {
            $userId = $user->id;

            $linkedIds = ProjectAssignee::where('employee_id', $userId)->pluck('project_id');

            $taskIds = DB::table('task_assignees')
                ->join('project_tasks', 'task_assignees.task_id', '=', 'project_tasks.id')
                ->where('task_assignees.employee_id', $userId)
                ->whereNull('project_tasks.deleted_at')
                ->pluck('project_tasks.project_id');

            $allIds = $linkedIds->merge($taskIds)->unique()->values();
            $query->whereIn('id', $allIds);
        }

        if ($search !== '') {
            $query->where('project_name', 'like', '%' . $search . '%');
        }

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $paginated->items(),
            'total'        => $paginated->total(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'has_more'     => $paginated->hasMorePages(),
        ]);
    }

    public function projectsWithTasks(Request $request)
    {
        $user = Auth::user();
        $task_status = $request->task_status ?? 'On Hold';
        $page = max(1, (int) $request->input('page', 1));
        $perPage = 10;

        $isPrivileged = ($user->employee_type == "Manager" || $user->employee_type == "Supervisor" || $user->employee_type == "Executive")
            && !$request->boolean('assigned_me');

        // Base parent-task query (same filters as before, but NO heavy relations yet)
        $baseParentQuery = ProjectTask::query()
            ->whereNull('parent_task_id')
            ->where('task_status', $task_status);

        if (!$isPrivileged) {
            $userId = $user->id;
            $baseParentQuery->where(function ($q) use ($userId) {
                $q->whereIn('project_id', function ($sq) use ($userId) {
                    $sq->select('project_id')
                        ->from('project_assignees')
                        ->where('employee_id', $userId);
                })->orWhereIn('id', function ($sq) use ($userId) {
                    $sq->select('task_id')
                        ->from('task_assignees')
                        ->where('employee_id', $userId);
                });
            });
        }

        // Phase 1: cheap skeleton — only IDs, to build the flat (expanded) row list
        $parentIds = (clone $baseParentQuery)->orderBy('id')->pluck('id');

        $subSkeleton = $parentIds->isEmpty()
            ? collect()
            : ProjectTask::whereIn('parent_task_id', $parentIds)
                ->orderBy('id')
                ->get(['id', 'parent_task_id'])
                ->groupBy('parent_task_id');

        // Build flat-row skeleton (identical expansion order to the previous implementation)
        $flat = [];
        foreach ($parentIds as $pid) {
            $subs = $subSkeleton->get($pid);
            if ($subs && $subs->isNotEmpty()) {
                foreach ($subs as $s) {
                    $flat[] = ['task_id' => $pid, 'sub_id' => $s->id];
                }
            } else {
                $flat[] = ['task_id' => $pid, 'sub_id' => null];
            }
        }

        $total = count($flat);
        $offset = ($page - 1) * $perPage;
        $pageRows = array_slice($flat, $offset, $perPage);

        // Phase 2: heavy-load ONLY the parent tasks needed for this page
        $neededParentIds = array_values(array_unique(array_map(fn($r) => $r['task_id'], $pageRows)));

        $parents = collect();
        if (!empty($neededParentIds)) {
            $parents = ProjectTask::with([
                'project',
                'assignees:id,employee_id,task_id',
                'assignees.user:id,name,profile_pic',
                'creator:id,name,profile_pic',
                'attachments',
                'subTasks',
                'subTasks.assignees:id,employee_id,task_id',
                'subTasks.assignees.user:id,name,profile_pic',
                'subTasks.creator:id,name,profile_pic',
                'subTasks.attachments',
            ])->whereIn('id', $neededParentIds)->get()->keyBy('id');
        }

        // Rebuild result rows in exact page order — same shape as before
        $paginated = [];
        foreach ($pageRows as $row) {
            $task = $parents->get($row['task_id']);
            if (!$task) {
                continue;
            }
            $sub = $row['sub_id'] !== null
                ? $task->subTasks->firstWhere('id', $row['sub_id'])
                : null;

            $paginated[] = [
                'project' => $task->project,
                'task' => $task,
                'sub_task' => $sub,
            ];
        }

        return response()->json([
            'data' => $paginated,
            'current_page' => (int) $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => (int) ceil($total / $perPage),
            'has_more' => ($offset + $perPage) < $total,
        ]);
    }

    public function projectsWithTasksCalendar(Request $request)
    {
        $user = Auth::user();

        // --- Date resolution ---
        $targetDate = $request->input('date')
            ? Carbon::parse($request->input('date'))->startOfDay()
            : Carbon::today();

        $page = max(1, (int) $request->input('page', 1));
        $perPage = 10;

        $isPrivileged = ($user->employee_type == "Manager"
            || $user->employee_type == "Supervisor"
            || $user->employee_type == "Executive")
            && !$request->boolean('assigned_me');

        // Base parent-task query (filters only, no heavy relations yet)
        $baseParentQuery = ProjectTask::query()
            ->whereNull('parent_task_id')
            ->where(function ($q) use ($targetDate) {
                $q->whereDate('start_date', '<=', $targetDate)
                    ->whereDate('due_date', '>=', $targetDate);
            });

        if (!$isPrivileged) {
            $userId = $user->id;
            $baseParentQuery->where(function ($q) use ($userId) {
                $q->whereIn('project_id', function ($sq) use ($userId) {
                    $sq->select('project_id')
                        ->from('project_assignees')
                        ->where('employee_id', $userId);
                })->orWhereIn('id', function ($sq) use ($userId) {
                    $sq->select('task_id')
                        ->from('task_assignees')
                        ->where('employee_id', $userId);
                });
            });
        }

        // Phase 1: cheap skeleton — parent ids + project ids, and subtask ids
        $parentSkeleton = (clone $baseParentQuery)->orderBy('id')->get(['id', 'project_id']);
        $parentIds = $parentSkeleton->pluck('id');

        $subSkeleton = $parentIds->isEmpty()
            ? collect()
            : ProjectTask::whereIn('parent_task_id', $parentIds)
                ->orderBy('id')
                ->get(['id', 'parent_task_id'])
                ->groupBy('parent_task_id');

        // Build flat-row skeleton (same expansion order as before)
        $flat = [];
        foreach ($parentIds as $pid) {
            $subs = $subSkeleton->get($pid);
            if ($subs && $subs->isNotEmpty()) {
                foreach ($subs as $s) {
                    $flat[] = ['task_id' => $pid, 'sub_id' => $s->id];
                }
            } else {
                $flat[] = ['task_id' => $pid, 'sub_id' => null];
            }
        }

        $total = count($flat);
        $offset = ($page - 1) * $perPage;
        $pageRows = array_slice($flat, $offset, $perPage);

        // --- Summary counts (computed from the cheap skeleton, before pagination) ---
        $totalSubIds = 0;
        foreach ($subSkeleton as $group) {
            $totalSubIds += $group->count();
        }
        $summary = [
            'total_projects' => $parentSkeleton->pluck('project_id')->unique()->count(),
            'total_tasks' => $parentIds->count(),
            'total_sub_tasks' => $totalSubIds,
            'total_rows' => $total,
        ];

        // Phase 2: heavy-load ONLY the parent tasks needed for this page
        $neededParentIds = array_values(array_unique(array_map(fn($r) => $r['task_id'], $pageRows)));

        $parents = collect();
        if (!empty($neededParentIds)) {
            $parents = ProjectTask::with([
                'project',
                'assignees:id,employee_id,task_id',
                'assignees.user:id,name,profile_pic',
                'creator:id,name,profile_pic',
                'attachments',
                'subTasks',
                'subTasks.assignees:id,employee_id,task_id',
                'subTasks.assignees.user:id,name,profile_pic',
                'subTasks.creator:id,name,profile_pic',
                'subTasks.attachments',
            ])->whereIn('id', $neededParentIds)->get()->keyBy('id');
        }

        // Rebuild rows in exact page order — same shape as before
        $paginated = [];
        foreach ($pageRows as $row) {
            $task = $parents->get($row['task_id']);
            if (!$task) {
                continue;
            }
            $sub = $row['sub_id'] !== null
                ? $task->subTasks->firstWhere('id', $row['sub_id'])
                : null;

            $paginated[] = [
                'project' => $task->project,
                'task' => $task,
                'sub_task' => $sub,
            ];
        }

        return response()->json([
            'date' => $targetDate->toDateString(),
            'summary' => $summary,
            'data' => $paginated,
            'current_page' => (int) $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => (int) ceil($total / $perPage),
            'has_more' => ($offset + $perPage) < $total,
        ]);
    }

    public function store(Request $request)
    {

        $user = Auth::user();

        if ($user->employee_type != "Manager" && $user->employee_type != "Supervisor" && $user->employee_type != "Executive") {

            return response()->json(['message' => 'Unauthorized'], 403);

        }

        $validated = $request->validate([
            'project_name' => 'required|string|max:255',
            'project_description' => 'nullable|string',
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'delivered_date' => 'nullable|date',
            'status' => 'nullable|string',
            'customer_id' => 'nullable|exists:users,id',
        ]);

        $project = Project::create($validated);

        //add project assignees 

        for ($i = 0; $i < count($request->employee_ids); $i++) {

            $proj_assignee = new ProjectAssignee();
            $proj_assignee->employee_id = $request->employee_ids[$i];
            $proj_assignee->project_id = $project->id;
            $proj_assignee->save();


            $user = User::find($request->employee_ids[$i]);
            $projectId = $project->id;

            if ($user) {

                $project_detail = Project::find($projectId);
                $from_user = User::find(\Auth::user()->id);
                $nature = "primary";
                $message = $from_user->name . " has assigned you a job " . $project_detail->project_name;

                insertNotificationWithNature($user->id, \Auth::user()->id, "project_assigned", $message, $nature, $projectId);
                sendAssignmentEmail($user, \Auth::user(), "project_assigned", $projectId);
            }

        }

        //ending add project assignees

        return response()->json([
            'message' => 'Project created successfully.',
            'project' => $project,
        ]);
    }


    public function show(Request $request, $id)
    {

        // Lightweight mode (used by task-detail breadcrumb) — skip heavy task + hours computation
        if ($request->boolean('light')) {
            $project = Project::with(['customer:id,name,profile_pic'])->findOrFail($id);
            return response()->json($project);
        }
        $project = Project::with([
            'projectAssignees',
            'projectAssignees.user',
            'customer',
            'allTasks',
            'tasks' => function ($q) { $q->withCount('subTasks'); },
            'tasks.creator',
            'tasks.assignees',
            'tasks.assignees.user',
            'tasks.attachments',
            'allBriefs',
            'allBriefs.attachments',
            'allNotes'
        ])->findOrFail($id);

        // Flag parent tasks that have an Urgent task nested inside them.
        $this->annotateUrgency($project->tasks, $this->urgentAncestorSet($project->id));

        $startDateFilter = $request->summary_start_date ?? null;
        $endDateFilter   = $request->summary_end_date   ?? null;

        $allTaskIds = $project->allTasks->pluck('id')->toArray();
        $taskHours  = [];

        if (!empty($allTaskIds)) {
            // 1. ONE query: all sessions for all tasks in this project
            $sessionsQuery = WorkSession::whereIn('task_id', $allTaskIds);

            if ($startDateFilter && $endDateFilter) {
                $sessionsQuery->where(function ($q) use ($startDateFilter, $endDateFilter) {
                    $q->whereBetween('start_date', [$startDateFilter, $endDateFilter])
                        ->orWhereBetween('end_date', [$startDateFilter, $endDateFilter])
                        ->orWhere(function ($q2) use ($startDateFilter, $endDateFilter) {
                            $q2->where('start_date', '<', $startDateFilter)
                                ->where('end_date', '>', $endDateFilter);
                        });
                });
            } elseif ($startDateFilter) {
                $sessionsQuery->where(function ($q) use ($startDateFilter) {
                    $q->whereDate('start_date', '>=', $startDateFilter)
                        ->orWhereDate('end_date', '>=', $startDateFilter);
                });
            } elseif ($endDateFilter) {
                $sessionsQuery->where(function ($q) use ($endDateFilter) {
                    $q->whereDate('start_date', '<=', $endDateFilter)
                        ->orWhereDate('end_date', '<=', $endDateFilter);
                });
            }

            $allSessions = $sessionsQuery->get();

            // 2. ONE query: all adjustments for those sessions
            $allSessionIds        = $allSessions->pluck('id')->toArray();
            $adjustmentsBySession = !empty($allSessionIds)
                ? DB::table('session_time_adjustments')
                    ->whereIn('session_id', $allSessionIds)
                    ->get()
                    ->groupBy('session_id')
                : collect();

            // 3. Group sessions by task_id; compute hours in PHP — zero extra queries
            $sessionsByTask = $allSessions->groupBy('task_id');

            foreach ($allTaskIds as $taskId) {
                $sessions     = $sessionsByTask->get($taskId, collect());
                $totalSeconds = 0;

                foreach ($sessions as $session) {
                    try {
                        $sessionStart = Carbon::parse($session->start_date . ' ' . $session->start_time);
                        $sessionEnd   = is_null($session->end_time)
                            ? now()
                            : Carbon::parse(($session->end_date ?? $session->start_date) . ' ' . $session->end_time);

                        $sessionDuration   = abs($sessionEnd->diffInSeconds($sessionStart));

                        // Merge overlapping idle rows and clamp them to this session's own
                        // window, so the same minute can never be subtracted twice and idle
                        // can never exceed the session duration.
                        $adjustmentSeconds = $this->sessionIdleSeconds(
                            $adjustmentsBySession->get($session->id, collect()),
                            $sessionStart,
                            $sessionEnd
                        );

                        $netSeconds = (int) max(0, $sessionDuration - $adjustmentSeconds);
                        if ($netSeconds > 0) {
                            $totalSeconds += $netSeconds;
                        }
                    } catch (\Exception $e) {
                        continue;
                    }
                }

                $taskHours[$taskId] = $totalSeconds;
            }
        } else {
            foreach ($project->allTasks as $task) {
                $taskHours[$task->id] = 0;
            }
        }

        // Roll every task's hours up to its FIRST-LEVEL task, however deep it sits. (This used to
        // add a task only to its direct parent, so a sub-sub-task's time never reached the top.)
        $rootOf = $this->rootTaskMap(
            $project->allTasks->mapWithKeys(fn ($t) => [(int) $t->id => $t->parent_task_id ? (int) $t->parent_task_id : null])->all()
        );
        $rolledUpHours = [];
        foreach ($project->allTasks as $task) {
            $root = $rootOf[(int) $task->id] ?? (int) $task->id;
            $rolledUpHours[$root] = ($rolledUpHours[$root] ?? 0) + ($taskHours[$task->id] ?? 0);
        }

        $parentTasksWithHours = [];
        foreach ($project->tasks->whereNull('parent_task_id') as $parentTask) {
            $totalHours             = $rolledUpHours[$parentTask->id] ?? 0;
            $parentTasksWithHours[] = [
                'task_id'               => $parentTask->id,
                'task_title'            => $parentTask->task_title,
                'total_hours'           => $totalHours,
                'total_hours_formatted' => $this->formatHours($totalHours),
            ];
        }

        $project->tasks_hours_summary = $parentTasksWithHours;

        return response()->json($project);
    }



    // Helper method to format seconds into hours and minutes
    private function formatHours($seconds)
    {
        if ($seconds <= 0) {
            return '0h 0m';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        return sprintf('%dh %dm', $hours, $minutes);
    }

    public function update(Request $request, $id)
    {

        $user = Auth::user();

        /*

        if($user->employee_type != "Manager"){

            return response()->json(['message' => 'Unauthorized'], 403);

        } */


        $project = Project::findOrFail($id);

        $validated = $request->validate([
            'project_name' => 'sometimes|string|max:255',
            'project_description' => 'nullable|string',
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'delivered_date' => 'nullable|date',
            'status' => 'in:In Progress,Pending,Completed,Cancelled',
            'customer_id' => 'nullable|exists:users,id',
        ]);

        if ($project->due_date != $request->due_date) {

            dueChangedNotification($id, $request->due_date, $type = "project_due_date_updated");

        }

        $project->update($validated);

        return response()->json([
            'message' => 'Project updated successfully.',
            'project' => $project,
        ]);
    }

    public function destroy($id)
    {

        $user = Auth::user();

        if ($user->employee_type != "Manager" && $user->employee_type != "Supervisor" && $user->employee_type != "Executive") {

            return response()->json(['message' => 'Unauthorized'], 403);

        }


        $project = Project::findOrFail($id);
        $project->delete();

        return response()->json([
            'message' => 'Project deleted successfully.',
        ]);
    }


}
