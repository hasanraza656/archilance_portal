<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use App\Models\ProjectTask;
use App\Models\TaskAssignee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Project managers — which assignees of a FIRST-LEVEL task manage it.
 *
 * Naming trap: in the database a "project" is what the portal calls a JOB, and a
 * job's first-level tasks (parent_task_id IS NULL) are what the portal calls
 * PROJECTS. Managers belong to those first-level tasks, because that is the unit
 * a manager actually runs. Sub-tasks never carry a manager of their own.
 *
 *   GET  /{admin|employee}/task-managers?task_id=  who manages it
 *   POST /{admin|employee}/task-managers           set / unset one person
 *
 * A task can have several managers, and only people already assigned to that
 * task (task_assignees) can be made one. Who the managers are decides who rates
 * whom in the monthly manager evaluation.
 *
 * Setting managers is limited to admins, executives and managers; everyone else
 * can only read. Until the `is_manager` column exists every endpoint answers
 * "not available yet" instead of erroring, so this can be deployed first.
 * Re-assigning a task's people never clears the flag: the assignee controllers
 * only delete the people who were removed and add the new ones.
 */
class TaskManagerController extends Controller
{
    /** Admins, executives and managers may set project managers; everyone else can only read. */
    private function canManage($user): bool
    {
        return (int) $user->user_role === 2 || in_array($user->employee_type, ['Executive', 'Manager'], true);
    }

    private function managersPayload(int $taskId): array
    {
        $ids = TaskAssignee::managerIds($taskId);

        if (empty($ids)) {
            return [];
        }

        return User::whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name', 'profile_pic', 'employee_type'])
            ->map(fn ($u) => [
                'employee_id' => $u->id,
                'name' => $u->name,
                'profile_pic' => $u->profile_pic,
                'employee_type' => $u->employee_type,
            ])
            ->values()
            ->all();
    }

    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), ['task_id' => 'required|integer|exists:project_tasks,id']);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $task = ProjectTask::find((int) $request->task_id, ['id', 'parent_task_id']);

        return response()->json([
            'supported' => TaskAssignee::supportsManagers(),
            'can_manage' => $this->canManage(Auth::user()),
            // Only first-level tasks can have managers; the UI hides the control otherwise.
            'applies' => $task && $task->parent_task_id === null,
            'data' => $this->managersPayload((int) $request->task_id),
        ]);
    }

    public function update(Request $request)
    {
        $actor = Auth::user();

        if (!$this->canManage($actor)) {
            return response()->json(['message' => 'Only an admin, executive or manager can set project managers.'], 403);
        }

        if (!TaskAssignee::supportsManagers()) {
            return response()->json(['message' => 'Project managers are not available yet.'], 503);
        }

        $validator = Validator::make($request->all(), [
            'task_id' => 'required|integer|exists:project_tasks,id',
            'employee_id' => 'required|integer',
            'is_manager' => 'required|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $taskId = (int) $request->task_id;
        $employeeId = (int) $request->employee_id;

        $task = ProjectTask::find($taskId, ['id', 'parent_task_id']);
        if (!$task) {
            return response()->json(['message' => 'Task not found.'], 404);
        }

        if ($task->parent_task_id !== null) {
            return response()->json(['message' => 'Only a first-level project can have a manager, not a sub-task.'], 422);
        }

        // Only an existing assignee can be made a manager.
        $rows = TaskAssignee::where('task_id', $taskId)->where('employee_id', $employeeId);

        if (!$rows->exists()) {
            return response()->json(['message' => 'Assign this person to the project first.'], 422);
        }

        $rows->update(['is_manager' => $request->boolean('is_manager')]);

        return response()->json([
            'message' => $request->boolean('is_manager') ? 'Project manager added.' : 'Project manager removed.',
            'supported' => true,
            'can_manage' => true,
            'applies' => true,
            'data' => $this->managersPayload($taskId),
        ]);
    }
}
