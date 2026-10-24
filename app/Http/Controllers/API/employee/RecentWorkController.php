<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use App\Models\ProjectTask;
use App\Models\WorkSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * GET /employee/recent-work
 *
 * The tasks the signed-in person has worked on lately, most recent first, so the time
 * tracker can offer "pick up where you left off" before they have to search for a client.
 * Self only (no id parameter), read only, and tolerant of tasks/projects that have since
 * been removed or completed.
 */
class RecentWorkController extends Controller
{
    private const LOOKBACK_DAYS = 60;
    private const MAX_ITEMS = 12;

    public function index()
    {
        $userId = Auth::id();

        $sessions = WorkSession::where('user_id', $userId)
            ->whereNotNull('task_id')
            ->where('start_date', '>=', Carbon::today()->subDays(self::LOOKBACK_DAYS)->toDateString())
            ->orderByDesc('start_date')
            ->orderByDesc('start_time')
            ->limit(400)
            ->get(['task_id', 'start_date', 'start_time']);

        // Newest first, so the first time a task appears is its latest use.
        $byTask = [];
        foreach ($sessions as $s) {
            $id = (int) $s->task_id;
            if (!isset($byTask[$id])) {
                $byTask[$id] = ['last' => trim($s->start_date . ' ' . $s->start_time), 'sessions' => 0];
            }
            $byTask[$id]['sessions']++;
        }

        if (empty($byTask)) {
            return response()->json(['data' => []]);
        }

        $tasks = ProjectTask::with('project:id,project_name')
            ->whereIn('id', array_keys($byTask))
            ->get(['id', 'task_title', 'task_status', 'project_id'])
            ->keyBy('id');

        $out = [];
        foreach ($byTask as $taskId => $info) {
            $task = $tasks->get($taskId);

            // Skip tasks that no longer exist, have no project, or are already finished.
            if (!$task || !$task->project || strcasecmp((string) $task->task_status, 'Completed') === 0) {
                continue;
            }

            try {
                $last = Carbon::parse($info['last'])->toIso8601String();
            } catch (\Throwable $e) {
                continue;
            }

            $out[] = [
                'task_id' => (int) $task->id,
                'task_title' => (string) $task->task_title,
                'project_id' => (int) $task->project->id,
                'project_name' => (string) $task->project->project_name,
                'last_used' => $last,
                'sessions' => $info['sessions'],
            ];

            if (count($out) >= self::MAX_ITEMS) {
                break;
            }
        }

        return response()->json(['data' => $out]);
    }
}
