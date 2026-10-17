<?php

namespace App\Http\Controllers\API\customer;

use App\Http\Controllers\Controller;
use App\Traits\CalculatesIdleTime;
use App\Traits\ResolvesTaskTree;
use Illuminate\Http\Request;
use App\Models\Project;
use App\Models\ProjectTask;
use Auth;
use App\Models\CustomerTeam;
use App\Models\WorkSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;


class ProjectController extends Controller
{
    use CalculatesIdleTime, ResolvesTaskTree;

    public function index()
    {
        $user = Auth::user();

        $withRelations = [
            'customer:id,name,profile_pic',
            'projectAssignees:id,employee_id,project_id',
            'projectAssignees.user:id,name,profile_pic',
        ];

        if ($user->user_role == 5) {
            $customerIds = CustomerTeam::where('email', $user->email)
                ->where('status', 'Approved')
                ->pluck('customer_id');

            $projects = Project::latest()
                ->with($withRelations)
                ->whereIn('customer_id', $customerIds)
                ->get();
        } else {
            $projects = Project::latest()
                ->with($withRelations)
                ->where('customer_id', $user->id)
                ->get();
        }

        return response()->json($projects);
    }


    public function projectsWithTasks(Request $request)
    {
        $user = Auth::user();

        // Step 1: Get customer IDs for this user (handles both customers & team members)
        if ($user->user_role == 5) {
            // TEAM MEMBER → fetch related customer IDs
            $customerIds = CustomerTeam::where('email', $user->email)
                ->where('status', 'Approved')
                ->pluck('customer_id');
        } else {
            // CUSTOMER → just their own ID
            $customerIds = [$user->id];
        }

        $page = $request->input('page', 1);
        $perPage = 10;

        // Use whereIn with subquery instead of whereHas for better performance
        $query = ProjectTask::with([
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
        ])
            ->whereNull('parent_task_id')
            ->whereIn('project_id', function ($sq) use ($customerIds) {
                $sq->select('id')->from('projects')->whereIn('customer_id', $customerIds)->whereNull('deleted_at');
            })
            ->latest();

        if ($request->filled('task_status')) {
            $query->where('task_status', $request->task_status);
        }

        $mainTasks = $query->get();
        $result = [];

        foreach ($mainTasks as $task) {
            if ($task->subTasks->isNotEmpty()) {
                foreach ($task->subTasks as $sub) {
                    $result[] = [
                        'project' => $task->project,
                        'task' => $task,
                        'sub_task' => $sub,
                    ];
                }
            } else {
                $result[] = [
                    'project' => $task->project,
                    'task' => $task,
                    'sub_task' => null,
                ];
            }
        }

        $total = count($result);
        $offset = ($page - 1) * $perPage;
        $paginated = array_slice($result, $offset, $perPage);

        return response()->json([
            'data' => $paginated,
            'current_page' => (int) $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => (int) ceil($total / $perPage),
            'has_more' => ($offset + $perPage) < $total,
        ]);
    }



     public function show(Request $request, $id)
    {
        $project = Project::with([
            'projectAssignees',
            'projectAssignees.user',
            'customer',
            'allTasks',
            'tasks',
            'tasks.creator',
            'tasks.assignees',
            'tasks.assignees.user',
            'tasks.attachments',
            'allBriefs',
            'allBriefs.attachments',
            'allNotes'
        ])->findOrFail($id);

        $taskHours = [];

        // 1. Calculate hours for every task (including child tasks)
        // Get date filters (if supplied)
        $startDateFilter = $request->summary_start_date ?? null;
        $endDateFilter = $request->summary_end_date ?? null;
        
      //  \Log::info($project->allTasks);

       foreach ($project->allTasks as $task) {
            $taskHours[$task->id] = $this->calculateEmployeeTaskHours(
                null,
                $task->id,
                $startDateFilter,
                $endDateFilter
            );
        }
        // 2. Roll every task's hours up to its FIRST-LEVEL task, however deep it sits.
        $rootOf = $this->rootTaskMap(
            $project->allTasks->mapWithKeys(fn ($t) => [(int) $t->id => $t->parent_task_id ? (int) $t->parent_task_id : null])->all()
        );
        $rolledUpHours = [];
        foreach ($project->allTasks as $task) {
            $root = $rootOf[(int) $task->id] ?? (int) $task->id;
            $rolledUpHours[$root] = ($rolledUpHours[$root] ?? 0) + ($taskHours[$task->id] ?? 0);
        }

        // 3. Build array of parent tasks with their total hours
        $parentTasksWithHours = [];
        foreach ($project->tasks->whereNull('parent_task_id') as $parentTask) {
            
            $totalHours = $rolledUpHours[$parentTask->id] ?? 0;
            $parentTasksWithHours[] = [
                'task_id' => $parentTask->id,
                'task_title' => $parentTask->task_title,
                'total_hours' => $totalHours,
                'total_hours_formatted' => $this->formatHours($totalHours),
            ];
        }

        // 4. Attach to project response
        $project->tasks_hours_summary = $parentTasksWithHours;

        return response()->json($project);
    }
    

    private function calculateEmployeeTaskHours($employeeId=null, $taskId, $startDateFilter = null, $endDateFilter = null)
    {
        
        $sessionsQuery = WorkSession::where('task_id', $taskId);

    
        // Apply date range if provided
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
    
        $sessions = $sessionsQuery->get();
        
       // \Log::info($sessions);
    
        $totalSeconds = 0;
        $dateWiseTotals = [];
        $dateWiseAdjustments = [];
    
        foreach ($sessions as $session) {
            try {
                // Parse start time
                $sessionStart = Carbon::parse($session->start_date . ' ' . $session->start_time);
    
                // Parse end time (handle running sessions and null end dates)
                if (is_null($session->end_time)) {
                    $sessionEnd = now();
                } else {
                    $endDate = $session->end_date ?? $session->start_date;
                    $sessionEnd = Carbon::parse($endDate . ' ' . $session->end_time);
                }
                
                
                
                
    
                // Calculate session duration
                $sessionDuration = abs($sessionEnd->diffInSeconds($sessionStart));
    
                // Subtract adjustments
                $adjustmentSeconds = 0;
                $adjustments = DB::table('session_time_adjustments')
                    ->where('session_id', $session->id)
                    ->get();

                // Merge overlapping idle rows and clamp them to this session's own window,
                // so the same minute can never be subtracted twice and idle can never
                // exceed the session duration.
                $adjustmentSeconds = $this->sessionIdleSeconds($adjustments, $sessionStart, $sessionEnd);
    
                // Compute net worked time
                $netSeconds = $sessionDuration - $adjustmentSeconds;
               //$netSeconds = $sessionDuration;
               
               
               
               
    
                if ($netSeconds > 0) {
                    $totalSeconds += $netSeconds;
    
                    $date = Carbon::parse($session->start_date)->format('Y-m-d');
    
                    if (!isset($dateWiseTotals[$date])) {
                        $dateWiseTotals[$date] = 0;
                    }
                    if (!isset($dateWiseAdjustments[$date])) {
                        $dateWiseAdjustments[$date] = 0;
                    }
    
                    $dateWiseTotals[$date] += $netSeconds;
                    $dateWiseAdjustments[$date] += $adjustmentSeconds;
                }
                
                
                if($session->task_id == 433){
                    
                       // \Log::info('session start date '.$session->start_date);
                       // \Log::info('session end date '.$session->end_date);
                        
                      //  \Log::info('session start time '.$session->start_time);
                      //  \Log::info('session end time '.$session->end_time);
                        
                       // \Log::info('session duration '.$sessionDuration);
                      //  \Log::info('idle duration '.$adjustmentSeconds);
                        
                       // \Log::info('netSeconds '.$netSeconds);
                        
                     //    \Log::info('total seconds '.$totalSeconds);
                    
                }
    
            } catch (\Exception $e) {
                continue;
            }
        }
    
        // ✅ Log date-wise totals and adjustments
        foreach ($dateWiseTotals as $date => $seconds) {
            $hours = round($seconds / 3600, 2);
            $adjustHrs = isset($dateWiseAdjustments[$date]) ? round($dateWiseAdjustments[$date] / 3600, 2) : 0;
            $totalWithAdj = round(($seconds + $dateWiseAdjustments[$date]) / 3600, 2);
    
           // \Log::info("[$date] Employee $employeeId - Task $taskId: Worked {$hours} hrs | Adjusted {$adjustHrs} hrs | Original {$totalWithAdj} hrs");
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
}
