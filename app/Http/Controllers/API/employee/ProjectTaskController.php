<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use App\Traits\CalculatesIdleTime;
use App\Traits\AnnotatesTaskUrgency;
use App\Traits\ResolvesTaskTree;
use Illuminate\Http\Request;

use App\Models\ProjectTask;
use App\Models\TaskAssignee;
use App\Models\TaskAttachment;
use Illuminate\Support\Facades\Storage;
use App\Models\ProjectAssignee;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\WorkSession;
use App\Models\Project;
use App\Services\OneDriveService;
use Illuminate\Support\Facades\Auth;




class ProjectTaskController extends Controller
{
    use AnnotatesTaskUrgency, CalculatesIdleTime, ResolvesTaskTree;

    // ✅ Get all tasks (optionally filtered by project)
    public function index(Request $request)
    {
        $user = Auth::user();

        if($user->employee_type != "Internee"){

            $query = ProjectTask::with(['assignees', 'assignees.user', 'creator', 'attachments'])->withCount('subTasks');

            if ($request->has('project_id')) {
                $query->where('project_id', $request->project_id);
            }

            $tasks = $query->whereNull('parent_task_id')->get();

        }else{

            $userId = $user->id;

            $taskIds = DB::table('task_assignees')
                ->join('project_tasks', 'task_assignees.task_id', '=', 'project_tasks.id')
                ->where('task_assignees.employee_id', $userId)
                ->whereNull('project_tasks.deleted_at')
                ->pluck('project_tasks.id');

             $query = ProjectTask::with(['assignees', 'assignees.user', 'creator', 'attachments'])->withCount('subTasks');

            if ($request->has('project_id')) {
                $query->where('project_id', $request->project_id);
            }

            $tasks = $query->whereNull('parent_task_id')->whereIn('id',$taskIds)->get();

        }

      
        if ($request->filled('project_id')) {
            $this->annotateUrgency($tasks, $this->urgentAncestorSet($request->project_id));
        }

        return response()->json($tasks);
    }

    public function allTasks()
    {
        $user = Auth::user();

        $query = ProjectTask::with([
            'assignees',
            'assignees.user',
            'creator',
            'attachments',
            'parentTask'
        ]);

        // If Employee → only show assigned tasks
        if ($user->employee_type === "Employee") {
            $query->whereHas('assignees', function ($q) use ($user) {
                $q->where('employee_id', $user->id);
            });
        }

        $tasks = $query->get();

        return response()->json($tasks);
    }

    // ✅ Store new task
    public function store(Request $request)
    {
        //return response()->json($request->all());
        $request->validate([
            'project_id' => 'required|exists:projects,id',
            'task_title' => 'required|string|max:255',
            'task_status' => 'nullable|string',
            'priority' => 'nullable|string',
            'due_date' => 'nullable|date',
            'parent_task_id' => 'nullable|exists:project_tasks,id',
            'attachments.*' => 'nullable|file|max:10240',

            // New
            'employee_ids' => 'nullable|array',
            'employee_ids.*' => 'exists:users,id',
        ]);

        $task = ProjectTask::create([
            'project_id' => $request->project_id,
            'parent_task_id' => $request->parent_task_id,
            'created_by' => auth()->id(),
            'task_title' => $request->task_title,
            'task_description' => $request->task_description,
            'task_status' => $request->task_status ?? 'Backlog',
            'priority' => $request->priority ?? 'Normal',
            'due_date' => $request->due_date,
        ]);


        if ($request->task_status == "Backlog") {
            // Update project status
            $project_data = Project::find($request->project_id);
            if ($project_data) {
                $project_data->status = "Backlog";
                $project_data->update();
            }

            // ✅ Also update parent task status if subtask belongs to a parent
            if (!empty($request->parent_task_id)) {
                $parentTask = ProjectTask::find($request->parent_task_id);
                if ($parentTask) {
                    $parentTask->task_status = "Backlog";
                    $parentTask->update();
                }
            }
        }

        // ✅ Auto-assign the creator to the task
        $employeeIds = $request->employee_ids ?? [];

        // Always assign the creator as well
        $employeeIds[] = auth()->id();

        // Remove duplicate IDs
        $employeeIds = array_unique($employeeIds);

        foreach ($employeeIds as $employeeId) {
            TaskAssignee::create([
                'task_id' => $task->id,
                'employee_id' => $employeeId,
            ]);
        }

        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {

                $path = 'task_attachments/' . $task->id . '/' . uniqid() . '_' . $file->getClientOriginalName();

                // Upload to OneDrive
                app(OneDriveService::class)->upload(
                    $path,
                    file_get_contents($file->getRealPath())
                );

                TaskAttachment::create([
                    'task_id'   => $task->id,
                    'user_id'   => auth()->id(),
                    'file_path' => $path,
                    'file_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'file_name' => $file->getClientOriginalName(),
                ]);
            }
        }


        return response()->json(['message' => 'Task created and assigned successfully.', 'task' => $task]);
    }

    // ✅ Show single task
   public function show($id)
{
    $user = Auth::user();

    /*
    |--------------------------------------------------------------------------
    | Internee View
    |--------------------------------------------------------------------------
    */
    if ($user->employee_type === 'Internee') {

        $task = ProjectTask::with([
            'attachments',
            'subTasks' => function ($query) use ($user) {
                $query->with([
                    'assignees',
                    'assignees.user',
                    'creator',
                    'attachments'
                ])
                ->whereHas('assignees', function ($q) use ($user) {
                    $q->where('employee_id', $user->id);
                });
            }
        ])->findOrFail($id);

        $this->annotateUrgency(collect([$task]), $this->urgentAncestorSet($task->project_id));

        return response()->json($task);
    }

    /*
    |--------------------------------------------------------------------------
    | Normal View (Existing Logic)
    |--------------------------------------------------------------------------
    */
    $task = ProjectTask::with([
        'assignees',
        'assignees.user',
        'subTasks' => function ($q) { $q->withCount('subTasks'); },
        'subTasks.creator',
        'subTasks.assignees',
        'subTasks.assignees.user',
        'attachments',
        'allBriefs',
        'allBriefs.attachments',
        'allNotes'
    ])->findOrFail($id);

    $this->annotateUrgency(collect([$task]), $this->urgentAncestorSet($task->project_id));

    // 1. ONE query: all sessions for this task (across all employees)
    // A parent task's time includes everything logged on its sub-tasks, at any depth —
    // otherwise a parent whose people only ever tracked on sub-tasks showed 0h.
    $treeIds = $this->taskTreeIds((int) $task->id);
    $allSessions = WorkSession::whereIn('task_id', $treeIds)->get();

    // 2. ONE query: all adjustments for those sessions
    $allSessionIds = $allSessions->pluck('id')->toArray();

    $adjustmentsBySession = !empty($allSessionIds)
        ? DB::table('session_time_adjustments')
            ->whereIn('session_id', $allSessionIds)
            ->get()
            ->groupBy('session_id')
        : collect();

    // 3. Group sessions by employee; compute per-employee in PHP
    $sessionsByEmployee = $allSessions->groupBy('user_id');

    $assigneesWithHours = [];
    $seenEmployeeIds = [];
    $assignedInTree = \App\Models\TaskAssignee::whereIn('task_id', $treeIds)
        ->pluck('employee_id')->map(fn ($v) => (int) $v)->unique()->all();
    // Soft-deleted accounts are still real people with real hours: look them up
    // too, so their name shows instead of "Unknown".
    $trashedAssigneeUsers = \App\Models\User::onlyTrashed()
        ->whereIn('id', $task->assignees->pluck('employee_id')->all())
        ->get()
        ->keyBy('id');

    foreach ($task->assignees as $assignee) {

        $employeeId = $assignee->employee_id;
        $seenEmployeeIds[] = (int) $employeeId;

        $sessions = $sessionsByEmployee->get($employeeId, collect());
        $totalSeconds = $this->sumSessionsSeconds($sessions, $adjustmentsBySession);

        $assigneesWithHours[] = [
            'assignee'                      => $assignee,
            'user'                          => $assignee->user ?? $trashedAssigneeUsers->get((int) $assignee->employee_id),
            'total_working_hours'           => $totalSeconds,
            'total_working_hours_formatted' => $this->formatHours($totalSeconds),
        ];
    }

    // Anyone who logged time on this task but was later REMOVED as an assignee
    // must still show up here — the loop above only sees who is assigned RIGHT
    // NOW, so their hours silently vanished from this breakdown even though
    // $allSessions (the task's TOTAL, computed above from every session on this
    // task_id regardless of current assignment) always included them.
    $formerEmployeeIds = array_diff($sessionsByEmployee->keys()->map(fn ($id) => (int) $id)->all(), $seenEmployeeIds);

    if (!empty($formerEmployeeIds)) {
        // withTrashed(): a former assignee's account may since have been
        // deactivated/deleted too — their name should still appear rather
        // than silently falling back to "Unknown".
        $formerUsers = \App\Models\User::withTrashed()->whereIn('id', $formerEmployeeIds)->get()->keyBy('id');

        foreach ($formerEmployeeIds as $employeeId) {
            $totalSeconds = $this->sumSessionsSeconds(
                $sessionsByEmployee->get($employeeId, collect()),
                $adjustmentsBySession
            );

            if ($totalSeconds <= 0) {
                continue; // nothing meaningful to show
            }

            $assigneesWithHours[] = [
                'assignee'                      => null,
                'user'                          => $formerUsers->get($employeeId),
                'total_working_hours'           => $totalSeconds,
                'total_working_hours_formatted' => $this->formatHours($totalSeconds),
                'removed_from_task'             => !in_array((int) $employeeId, $assignedInTree, true),
            ];
        }
    }

    $task->assignees_with_hours = $assigneesWithHours;

    return response()->json($task);
}

    /**
     * Net seconds across a collection of WorkSession rows (duration minus merged,
     * clamped idle) — extracted so current-assignee and former-assignee totals
     * are computed identically.
     */
    private function sumSessionsSeconds($sessions, $adjustmentsBySession): int
    {
        $totalSeconds = 0;

        foreach ($sessions as $session) {
            try {
                $sessionStart = Carbon::parse($session->start_date . ' ' . $session->start_time);
                $sessionEnd   = is_null($session->end_time)
                    ? now()
                    : Carbon::parse(($session->end_date ?? $session->start_date) . ' ' . $session->end_time);

                $sessionDuration = abs($sessionEnd->diffInSeconds($sessionStart));

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

        return $totalSeconds;
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


    // ✅ Update task
    public function update(Request $request, $id)
    {
        $task = ProjectTask::findOrFail($id);

        $task_status_changed = 0;

        $request->validate([
            'task_title' => 'sometimes|required|string|max:255',
            'task_status' => 'nullable|string',
            'priority' => 'nullable|string',
            'due_date' => 'nullable|date',
            'attachments.*' => 'nullable|file|max:10240',
        ]);

        if($task->due_date != $request->due_date){

              dueChangedNotification($id, $request->due_date, $type="task_due_date_updated");

        }

        if ($task->task_status != $request->task_status) {
            $task_status_changed = 1;
        }

        $task->update($request->only([
            'task_title',
            'task_description',
            'task_status',
            'priority',
            'due_date',
            'completed_date',
        ]));

        // Add attachments
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {

                $path = 'task_attachments/' . $task->id . '/' . uniqid() . '_' . $file->getClientOriginalName();

                // Upload to OneDrive
                app(OneDriveService::class)->upload(
                    $path,
                    file_get_contents($file->getRealPath())
                );

                TaskAttachment::create([
                    'task_id'   => $task->id,
                    'user_id'   => auth()->id(),
                    'file_path' => $path,
                    'file_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'file_name' => $file->getClientOriginalName(),
                ]);
            }
        }

        // Delete attachments
        if ($request->has('delete_attachments')) {
            foreach ($request->delete_attachments as $attachmentId) {

                $attachment = TaskAttachment::where('task_id', $task->id)
                    ->where('id', $attachmentId)
                    ->first();

                if ($attachment) {
                    // Delete from OneDrive
                    app(OneDriveService::class)->delete($attachment->file_path);

                    // Delete DB record
                    $attachment->delete();
                }
            }
        }


        // ✅ NEW: Update parent Project (Job) Status based on all child tasks
        $this->updateParentProjectStatus($task->project_id);


        if ($task_status_changed == 1) {
            statusChangedNotification($id, $request->task_status, $type = "task_status_changed");
        }



        return response()->json([
            'message' => 'Task updated successfully.',
            'task' => $task->load('attachments')
        ]);
    }


    private function updateParentProjectStatus($projectId)
    {
        $project = Project::find($projectId);
        if (!$project)
            return;

        $statuses = ProjectTask::where('project_id', $projectId)->pluck('task_status')->toArray();

        $priority = [
            'In Progress' => 1,
            'Backlog' => 2,
            'In-house Review' => 3,
            'Awaiting Info' => 4,
            'On Hold' => 5,
            'Client Review' => 6,
            'Completed' => 7,
        ];

        // ✅ If all statuses are the same → use that status
        if (count(array_unique($statuses)) === 1) {
            $project->status = $statuses[0];
        } else {
            // ✅ Mixed statuses → find highest priority
            $project->status = collect($statuses)
                ->sortBy(fn($s) => $priority[$s] ?? 999)
                ->first();
        }

        $project->save();
    }


    // ✅ Delete task (soft delete if enabled)
    public function destroy($id)
    {
        $task = ProjectTask::findOrFail($id);
        $task->delete();

        return response()->json(['message' => 'Task deleted successfully.']);
    }

    public function changeOrder(Request $request)
    {

        $task = ProjectTask::findOrFail($request->task_id);

        $task->board_order = $request->board_order;
        $task->update();

        return response()->json(['message' => 'Task order updated successfully.']);

    }
}

