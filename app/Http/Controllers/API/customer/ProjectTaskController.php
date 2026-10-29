<?php

namespace App\Http\Controllers\API\customer;

use App\Http\Controllers\Controller;
use App\Traits\CalculatesIdleTime;
use App\Traits\ResolvesTaskTree;
use Illuminate\Http\Request;

use App\Models\ProjectTask;
use App\Models\TaskAttachment;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\WorkSession;

use App\Traits\AnnotatesTaskUrgency;

use App\Models\TaskAssignee;
use App\Models\ProjectAssignee;
use App\Models\Project;
use App\Services\OneDriveService;

class ProjectTaskController extends Controller
{
    use CalculatesIdleTime, ResolvesTaskTree;

    public function index(Request $request)
    {
        $query = ProjectTask::with(['assignees', 'assignees.user', 'comments', 'creator', 'attachments']);

        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        $tasks = $query->whereNull('parent_task_id')->get();

        // Attach each task's logged hours — its own sessions plus those of all its
        // sub-tasks — so the customer portal can show a duration next to every row
        // without a second round-trip per task.
        foreach ($tasks as $task) {
            $seconds = $this->calculateTaskTotalHours($this->taskTreeIds((int) $task->id));
            $task->total_hours = $seconds;
            $task->total_hours_formatted = $this->formatHours($seconds);
        }

        return response()->json($tasks);
    }

    public function show($id)
    {
        $task = ProjectTask::with([
            'assignees', 
            'assignees.user', 
            'comments', 
            'comments.sender',
            'comments.commentAttachments', 
            'subTasks', 
            'subTasks.creator', 
            'attachments', 
            'allBriefs', 
            'allBriefs.attachments',
            'allNotes'
        ])->findOrFail($id);

        // Calculate working hours for each assignee
        // A parent task's time includes everything logged on its sub-tasks, at any depth.
        $treeIds = $this->taskTreeIds((int) $task->id);
        $assignedInTree = \App\Models\TaskAssignee::whereIn('task_id', $treeIds)
            ->pluck('employee_id')->map(fn ($v) => (int) $v)->unique()->all();

        $assigneesWithHours = [];
        $seenEmployeeIds = [];
        // Soft-deleted accounts are still real people with real hours: look them up
        // too, so their name shows instead of "Unknown".
        $trashedAssigneeUsers = \App\Models\User::onlyTrashed()
            ->whereIn('id', $task->assignees->pluck('employee_id')->all())
            ->get()
            ->keyBy('id');

        foreach ($task->assignees as $assignee) {
            $employeeId = $assignee->employee_id;
            $seenEmployeeIds[] = (int) $employeeId;

            // Calculate total working hours for this employee on this task
            $totalHours = $this->calculateEmployeeTaskHours($employeeId, $treeIds);

            $assigneesWithHours[] = [
                'assignee' => $assignee,
                'user' => $assignee->user ?? $trashedAssigneeUsers->get((int) $assignee->employee_id),
                'total_working_hours' => $totalHours,
                'total_working_hours_formatted' => $this->formatHours($totalHours)
            ];
        }

        // Anyone who logged time on this task but was later REMOVED as an assignee
        // must still show up here — the loop above only sees who is assigned RIGHT
        // NOW, so their hours silently vanished from this breakdown even though
        // calculateTaskTotalHours() (this task's TOTAL, below) sums every session
        // on this task_id regardless of current assignment.
        $loggedEmployeeIds = WorkSession::whereIn('task_id', $treeIds)
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $formerEmployeeIds = array_diff($loggedEmployeeIds, $seenEmployeeIds);

        if (!empty($formerEmployeeIds)) {
            // withTrashed(): a former assignee's account may since have been
            // deactivated/deleted too — their name should still appear rather
            // than silently falling back to "Unknown".
            $formerUsers = \App\Models\User::withTrashed()->whereIn('id', $formerEmployeeIds)->get()->keyBy('id');

            foreach ($formerEmployeeIds as $employeeId) {
                $totalHours = $this->calculateEmployeeTaskHours($employeeId, $treeIds);

                if ($totalHours <= 0) {
                    continue; // nothing meaningful to show
                }

                $assigneesWithHours[] = [
                    'assignee' => null,
                    'user' => $formerUsers->get($employeeId),
                    'total_working_hours' => $totalHours,
                    'total_working_hours_formatted' => $this->formatHours($totalHours),
                    'removed_from_task' => !in_array((int) $employeeId, $assignedInTree, true),
                ];
            }
        }

        // Add the calculated hours to the task response
        $task->assignees_with_hours = $assigneesWithHours;

        // This task's own hours, plus each of its subtasks' own hours — lets
        // the customer portal show a duration on every row when a parent task
        // is expanded, computed here so no extra request is needed per row.
        $ownSeconds = $this->calculateTaskTotalHours($treeIds);
        $task->total_hours = $ownSeconds;
        $task->total_hours_formatted = $this->formatHours($ownSeconds);

        foreach ($task->subTasks as $subTask) {
            $subSeconds = $this->calculateTaskTotalHours($this->taskTreeIds((int) $subTask->id));
            $subTask->total_hours = $subSeconds;
            $subTask->total_hours_formatted = $this->formatHours($subSeconds);
        }

        return response()->json($task);
    }


    /**
     * Total logged hours for ONE task across every assignee (not scoped to a
     * single employee), used for the per-row duration pills the customer
     * portal shows on tasks and subtasks. Same net-of-idle math as
     * calculateEmployeeTaskHours below, just without the user_id filter.
     */
    private function calculateTaskTotalHours($taskIds)
    {
        $sessions = WorkSession::whereIn('task_id', (array) $taskIds)->get();
        $totalSeconds = 0;

        foreach ($sessions as $session) {
            try {
                $sessionStart = Carbon::parse($session->start_date . ' ' . $session->start_time);

                if (is_null($session->end_time)) {
                    $sessionEnd = now();
                } else {
                    $endDate = $session->end_date ?? $session->start_date;
                    $sessionEnd = Carbon::parse($endDate . ' ' . $session->end_time);
                }

                $sessionDuration = $sessionEnd->diffInSeconds($sessionStart);
                if ($sessionDuration < 0) {
                    $sessionDuration = $sessionStart->diffInSeconds($sessionEnd);
                }

                $adjustments = DB::table('session_time_adjustments')
                    ->where('session_id', $session->id)
                    ->get();
                $adjustmentSeconds = $this->sessionIdleSeconds($adjustments, $sessionStart, $sessionEnd);

                $netSeconds = $sessionDuration - $adjustmentSeconds;
                if ($netSeconds > 0) {
                    $totalSeconds += $netSeconds;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return $totalSeconds;
    }

     private function calculateEmployeeTaskHours($employeeId, $taskIds)
    {
       // \Log::info("Calculating hours for employee: $employeeId, task: $taskId");
        
        $sessions = WorkSession::where('user_id', $employeeId)
            ->whereIn('task_id', (array) $taskIds)
            ->get();

       // \Log::info("Found " . $sessions->count() . " work sessions");

        $totalSeconds = 0;

        foreach ($sessions as $session) {
          //  \Log::info("Processing session ID: " . $session->id);
           // \Log::info("Session data - Start: {$session->start_date} {$session->start_time}, End: {$session->end_date} {$session->end_time}");

            try {
                // Parse start time
                $sessionStart = Carbon::parse($session->start_date . ' ' . $session->start_time);
                
                // Parse end time (handle running sessions and null end dates)
                if (is_null($session->end_time)) {
                    $sessionEnd = now();
                  //  \Log::info("Session is still running, using current time");
                } else {
                    $endDate = $session->end_date ?? $session->start_date;
                    $sessionEnd = Carbon::parse($endDate . ' ' . $session->end_time);
                }

               // \Log::info("Parsed times - Start: " . $sessionStart->format('Y-m-d H:i:s') . 
                  //      ", End: " . $sessionEnd->format('Y-m-d H:i:s'));

                // Calculate duration - ensure it's positive
                $sessionDuration = $sessionEnd->diffInSeconds($sessionStart);
               // \Log::info("Raw session duration: $sessionDuration seconds");

                // If duration is negative, swap the times (this handles cases where end time is before start time)
                if ($sessionDuration < 0) {
                  //  \Log::warning("Negative duration detected, swapping start and end times");
                    $sessionDuration = $sessionStart->diffInSeconds($sessionEnd);
                  //  \Log::info("Corrected session duration: $sessionDuration seconds");
                }

                // Subtract adjustments
                $adjustmentSeconds = 0;
                $adjustments = DB::table('session_time_adjustments')
                    ->where('session_id', $session->id)
                    ->get();

                // Merge overlapping idle rows and clamp them to this session's own window,
                // so the same minute can never be subtracted twice and idle can never
                // exceed the session duration.
                $adjustmentSeconds = $this->sessionIdleSeconds($adjustments, $sessionStart, $sessionEnd);

                $netSeconds = $sessionDuration - $adjustmentSeconds;
               // \Log::info("Net seconds after adjustments: $netSeconds");
                
                if ($netSeconds > 0) {
                    $totalSeconds += $netSeconds;
                  //  \Log::info("Added to total: $netSeconds seconds");
                } else {
                  //  \Log::warning("Net seconds is negative or zero: $netSeconds, skipping");
                }

            } catch (\Exception $e) {
             //   \Log::error("Error processing session {$session->id}: " . $e->getMessage());
                continue;
            }
        }

       // \Log::info("Final total seconds: $totalSeconds");
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

        // Customer writes must stay within their own jobs, including when a
        // caller supplies a parent task directly rather than using the UI.
        if ((int) auth()->user()->user_role === 4) {
            Project::where('customer_id', auth()->id())->findOrFail($request->project_id);
        } else {
            // Keep the existing approved customer-team creation flow scoped too.
            $customerIds = \App\Models\CustomerTeam::where('email', auth()->user()->email)
                ->where('status', 'Approved')->pluck('customer_id');
            Project::whereIn('customer_id', $customerIds)->findOrFail($request->project_id);
        }

        if ($request->filled('parent_task_id')) {
            ProjectTask::where('project_id', $request->project_id)->findOrFail($request->parent_task_id);
        }

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

    public function update(Request $request, $id)
    {
        $task = $this->ownedTask($id);

        $request->validate([
            'task_description' => 'nullable|string',
            'delete_attachments' => 'nullable|array',
            'delete_attachments.*' => 'integer',
        ]);

        // Reuse the existing task updater (files, notifications and job status).
        // Inline edits send one field: preserve omitted values so they never
        // look like a status/deadline change to the shared controller.
        $request->replace(array_merge([
            'task_status' => $task->task_status,
            'due_date' => $task->due_date,
        ], $request->only([
            'task_title', 'task_description', 'task_status', 'priority',
            'due_date', 'delete_attachments',
        ])));

        return (new \App\Http\Controllers\API\admin\ProjectTaskController())->update($request, $id);
    }

    private function ownedTask($id): ProjectTask
    {
        abort_unless((int) auth()->user()->user_role === 4, 403);

        return ProjectTask::whereHas('project', function ($query) {
            $query->where('customer_id', auth()->id());
        })->findOrFail($id);
    }

    public function destroy($id)
    {
        $task = $this->ownedTask($id);
        $task->delete();

        return response()->json(['message' => 'Task deleted successfully.']);
    }

}
