<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Traits\CalculatesIdleTime;
use App\Traits\AnnotatesTaskUrgency;
use App\Traits\ResolvesTaskTree;
use App\Models\ProjectTask;
use Illuminate\Http\Request;
use App\Models\Project;
use App\Models\ProjectAssignee;
use App\Models\User;
use Firebase\JWT\JWT;
use App\Helpers\FirebaseHelper;
use App\Models\WorkSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;



class ProjectController extends Controller
{
    use AnnotatesTaskUrgency, CalculatesIdleTime, ResolvesTaskTree;

    public function index(Request $request)
    {
        $statusOrder = [
            'On Hold' => 1,
            'Backlog' => 2,
            'Awaiting Info' => 3,
            'In Progress' => 4,
            'In-house review' => 5,
            'Client Review' => 6,
            'Completed' => 7,
        ];

            $projects = Project::latest()
                ->with([
                    'customer:id,name,profile_pic',
                    'projectAssignees:id,employee_id,project_id',
                    'projectAssignees.user:id,name,profile_pic',
                ])
                ->withCount(['tasks', 'allTasks as urgent_count' => function ($q) {
                    $q->where('priority', 'Urgent');
                }])
                ->when($request->customer_id, function ($query) use ($request) {
                    $query->where('customer_id', $request->customer_id);
                })
                ->get();

        // ✅ Group projects by status
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

        return response()->json($grouped);
    }


    public function projectsWithTasks(Request $request)
    {
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = 10;

        // Ordering that preserves the previous status-priority sort, then id
        $statusPriority = "FIELD(task_status, 'On Hold','Backlog','Awaiting Info','In Progress','In-house review','Client Review','Completed')";

        // Base parent-task query (filters only, no heavy relations yet)
        $baseParentQuery = ProjectTask::query()
            ->whereNull('parent_task_id')
            ->where('task_status', '!=', 'Todo');

        if ($request->filled('task_status')) {
            $baseParentQuery->where('task_status', $request->task_status);
        }

        // Phase 1: cheap skeleton — parent ids in the exact display order
        $parentIds = (clone $baseParentQuery)
            ->orderByRaw($statusPriority)
            ->orderBy('id')
            ->pluck('id');

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

        $total    = count($flat);
        $offset   = ($page - 1) * $perPage;
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
                'project'  => $task->project,
                'task'     => $task,
                'sub_task' => $sub,
            ];
        }

        return response()->json([
            'data'         => $paginated,
            'current_page' => (int) $page,
            'per_page'     => $perPage,
            'total'        => $total,
            'last_page'    => (int) ceil($total / $perPage),
            'has_more'     => ($offset + $perPage) < $total,
        ]);
    }



    /**
     * Members View — every employee with their tasks grouped by status.
     *
     * OPT-IN slimming params (all absent = byte-identical legacy response, so the
     * existing frontend is unaffected):
     *   ?light=1        drop the duplicated flat `assignedTasks` array (every task
     *                   is already present under tasks_by_status) and return only
     *                   the user fields the members list actually renders, plus
     *                   trim each task to the fields the UI reads. This is the big
     *                   one — the legacy payload serializes every task TWICE and
     *                   ships full user rows.
     *   ?statuses=a,b   only include these task statuses.
     *   ?employee_id=N  only this employee.
     */
    public function projectsWithMember(Request $request)
    {
        $statusOrder = [
            'On Hold' => 1,
            'Backlog' => 2,
            'Awaiting Info' => 3,
            'In Progress' => 4,
            'In-house review' => 5,
            'Client Review' => 6,
            'Completed' => 7,
        ];

        $light = $request->boolean('light');

        $wantedStatuses = collect(explode(',', (string) $request->input('statuses')))
            ->map(fn($s) => trim($s))
            ->filter()
            ->values();

        $query = User::with([
            'assignedTasks' => function ($q) use ($wantedStatuses) {
                $q->with([
                    'project',
                    'parentTask',
                ])->withCount('subTasks')->where('task_status', '!=', 'Todo');

                if ($wantedStatuses->isNotEmpty()) {
                    $q->whereIn('task_status', $wantedStatuses->all());
                }
            }
        ])
            ->withCount([
                'assignedTasks as total_tasks' => function ($q) {
                    $q->where('task_status', '!=', 'Todo');
                    $q->where('task_status', '!=', 'Completed');
                    $q->where('task_status', '!=', 'Client Review');
                    $q->where('task_status', '!=', 'Awaiting Info');
                    $q->where('task_status', '!=', 'On Hold');
                }
            ])
            ->where('user_role', 3);

        if ($request->filled('employee_id')) {
            $query->where('id', (int) $request->input('employee_id'));
        }

        // OPT-IN peer visibility. Only applied when the caller explicitly asks
        // with ?peer_scope=1, so every existing client — the classic React app,
        // any already-built v2 bundle, the Electron tracker — keeps the exact
        // response it has today and cannot be affected by deploying this alone.
        // The response SHAPE is unchanged either way: still a JSON array of
        // users, just fewer rows when the filter applies.
        if ($request->boolean('peer_scope')) {
            $hidden = $this->peerHiddenUserIds();
            if (!empty($hidden)) {
                $query->whereNotIn('id', $hidden);
            }
        }

        $users = $query->get();

        // Only the statuses the client asked for still get a bucket; with no
        // filter every status appears (even empty) exactly as before.
        $bucketStatuses = $wantedStatuses->isNotEmpty()
            ? collect($statusOrder)->only($wantedStatuses->all())
            : collect($statusOrder);

        // Transform: group tasks by status & count them
        $users = $users->map(function ($user) use ($bucketStatuses, $light) {
            $grouped = $user->assignedTasks
                ->groupBy('task_status')
                ->map(function ($tasks, $status) use ($light) {
                    return [
                        'count' => $tasks->count(),
                        'tasks' => $light ? $tasks->map(fn($t) => $this->slimTask($t))->values() : $tasks,
                    ];
                });

            // Ensure all statuses appear, even if count = 0
            $ordered = $bucketStatuses->mapWithKeys(function ($order, $status) use ($grouped) {
                return [
                    $status => $grouped->get($status, [
                        'count' => 0,
                        'tasks' => collect(),
                    ])
                ];
            });

            if (!$light) {
                $user->tasks_by_status = $ordered;
                return $user;
            }

            // Slim mode: return ONLY what the members list renders.
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'profile_pic' => $user->profile_pic,
                'employee_type' => $user->employee_type,
                'total_tasks' => $user->total_tasks,
                'tasks_by_status' => $ordered,
            ];
        });

        return response()->json($users);
    }

    /**
     * Users a MANAGER or SUPERVISOR must not see, mirroring the rule already
     * enforced for leave requests (LeaveRequestController::managerHiddenUserIds).
     *
     *   admin / executive  -> see everyone (empty list)
     *   manager, supervisor-> cannot see other managers, supervisors or
     *                         executives, but always see themselves
     *   anyone else        -> only themselves
     *
     * Kept in step with the frontend's capabilitiesFor(); if one changes the
     * other must too, or the assistant and the API will disagree.
     */
    private function peerHiddenUserIds(): array
    {
        $viewer = \Auth::user();
        if (!$viewer) {
            return [];
        }

        $isAdminOrExecutive = (int) $viewer->user_role === 2
            || (int) $viewer->user_role === 7
            || strcasecmp((string) $viewer->employee_type, 'Executive') === 0;

        if ($isAdminOrExecutive) {
            return [];
        }

        $type = strtolower((string) $viewer->employee_type);

        if ($type === 'manager' || $type === 'supervisor') {
            return User::where(function ($q) {
                    $q->whereRaw('LOWER(employee_type) IN (?, ?, ?)', ['manager', 'supervisor', 'executive'])
                        ->orWhere('user_role', 7);
                })
                ->where('id', '!=', $viewer->id)
                ->pluck('id')
                ->all();
        }

        // Everyone else sees only their own row.
        return User::where('id', '!=', $viewer->id)->pluck('id')->all();
    }

    /** The task fields the Members View actually reads — nothing else. */
    private function slimTask($task)
    {
        return [
            'id' => $task->id,
            'task_title' => $task->task_title,
            'task_status' => $task->task_status,
            'priority' => $task->priority,
            'due_date' => $task->due_date,
            'project_id' => $task->project_id,
            'parent_task_id' => $task->parent_task_id,
            'sub_tasks_count' => $task->sub_tasks_count,
            'project' => $task->project ? [
                'id' => $task->project->id,
                'project_name' => $task->project->project_name,
            ] : null,
            'parent_task' => $task->parentTask ? [
                'id' => $task->parentTask->id,
                'task_title' => $task->parentTask->task_title,
            ] : null,
        ];
    }




    public function store(Request $request)
    {
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

            if($user){

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

    public function updateProjectAssignees(Request $request)
    {
        $projectId = $request->project_id;
        $newEmployeeIds = $request->employee_ids ?? [];

        // STEP 1: Get old assignees before deleting
        $oldEmployeeIds = ProjectAssignee::where('project_id', $projectId)
                            ->pluck('employee_id')
                            ->toArray();

        // STEP 2: Find ONLY the newly added employees
        $newlyAdded = array_diff($newEmployeeIds, $oldEmployeeIds);

        // STEP 3: Delete old records
        ProjectAssignee::where('project_id', $projectId)->delete();

        // STEP 4: Insert new assignees
        foreach ($newEmployeeIds as $empId) {
            ProjectAssignee::create([
                'employee_id' => $empId,
                'project_id' => $projectId,
            ]);
        }

        // STEP 5: Send FCM notification ONLY to newly added employees
        foreach ($newlyAdded as $empId) {
            $user = User::find($empId);

            if ($user && $user->fcm_token) {
                FirebaseHelper::sendFcmNotification(
                    $user->fcm_token,
                    "New Task",
                    "A new task/project has been assigned to you."
                );
            }

            if($user){

                $project_detail = Project::find($projectId);
                $from_user = User::find(\Auth::user()->id);
                $nature = "primary";
                $message = $from_user->name . " has assigned you a job " . $project_detail->project_name;

                insertNotificationWithNature($user->id, \Auth::user()->id, "project_assigned", $message, $nature, $projectId);
                sendAssignmentEmail($user, \Auth::user(), "project_assigned", $projectId);

            }
        }

        $project = Project::find($projectId);



        return response()->json([
            'message' => 'Project updated successfully.',
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

        if($project->due_date != $request->due_date){

              dueChangedNotification($id, $request->due_date, $type="project_due_date_updated");

        }

        $project->update($validated);

        return response()->json([
            'message' => 'Project updated successfully.',
            'project' => $project,
        ]);
    }

    public function updateStatus(Request $request)
    {

        $project = Project::findOrFail($request->project_id);
        $project->status = $request->status;
        $project->save();

        if ($project->status == "Completed") {

            ProjectTask::where('project_id', $request->project_id)->update(['task_status' => "Completed"]);

        }

        statusChangedNotification($request->project_id, $request->status, $type="project_status_changed");

        return response()->json([
            'message' => 'Project updated successfully.',
            'project' => $project,
        ]);

    }

    public function destroy($id)
    {
        $project = Project::findOrFail($id);
        $project->delete();

        return response()->json([
            'message' => 'Project deleted successfully.',
        ]);
    }
    
    
    public function testData(){
        
        $data = ProjectTask::where('project_id',26)->pluck('id')->toArray();
        
        $works = WorkSession::whereIn('task_id', $data)
            ->selectRaw('user_id, COUNT(*) as total_sessions')
            ->groupBy('user_id')
            ->get();

        
        return response()->json($works);
            
        
    }
    
    
    public function projectsWithTasksCalendar(Request $request)
    {
        // --- Date resolution ---
        $targetDate = $request->input('date')
            ? Carbon::parse($request->input('date'))->startOfDay()
            : Carbon::today();

        $page    = max(1, (int) $request->input('page', 1));
        $perPage = 10;

        // --- Base parent-task query (filters only, no heavy relations yet) ---
        $baseParentQuery = ProjectTask::query()
            ->whereNull('parent_task_id')
            ->whereDate('due_date', $targetDate)
            ->whereNull('completed_date');

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

        $total     = count($flat);
        $offset    = ($page - 1) * $perPage;
        $pageRows  = array_slice($flat, $offset, $perPage);

        // --- Summary counts for the date (from the cheap skeleton, before pagination) ---
        $totalSubIds = 0;
        foreach ($subSkeleton as $group) {
            $totalSubIds += $group->count();
        }
        $uniqueTaskIds    = $parentIds->count();
        $uniqueSubIds     = $totalSubIds;
        $uniqueProjectIds = $parentSkeleton->pluck('project_id')->unique()->count();

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
                'project'  => $task->project,
                'task'     => $task,
                'sub_task' => $sub,
            ];
        }

        return response()->json([
            'date'         => $targetDate->toDateString(),
            'summary'      => [
                'total_projects'  => $uniqueProjectIds,
                'total_tasks'     => $uniqueTaskIds,
                'total_sub_tasks' => $uniqueSubIds,
                'total_rows'      => $total,
            ],
            'data'         => $paginated,
            'current_page' => (int) $page,
            'per_page'     => $perPage,
            'total'        => $total,
            'last_page'    => (int) ceil($total / $perPage),
            'has_more'     => ($offset + $perPage) < $total,
        ]);
    }


}
