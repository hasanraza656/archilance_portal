<?php

namespace App\Http\Controllers\API\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Screenshot;
use App\Models\User;
use App\Models\WorkSession;
use App\Models\TrackWindow;
use App\Traits\BuildsWindowActivity;
use App\Traits\ResolvesTaskTree;
use Auth;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\DB;

class WorkSessionController extends Controller
{
    use BuildsWindowActivity;
    use ResolvesTaskTree;


   public function index(Request $request)
    {
        try {
            
        $check_employee_user = User::find($request->employee_id);
        
        if(Auth::check() && Auth::user()->employee_type == "Manager"){
            
            if($check_employee_user->employee_type == "Manager"){
                return response()->json('Unauthorized',401);
            }
            
        }
            
        $query = WorkSession::with('screenshots', 'taskDetail', 'userDetail', 'idleTimes')
        ->where('user_id', $request->employee_id);
    
        // Use current date if no dates provided
        $filterStartDate = $request->start_date ?? now()->toDateString();
        $filterEndDate = $request->end_date ?? $filterStartDate;
        
        // Date filters
        $query->where(function($q) use ($filterStartDate, $filterEndDate) {
            $q->whereBetween('start_date', [$filterStartDate, $filterEndDate])
              ->orWhereBetween('end_date', [$filterStartDate, $filterEndDate])
              ->orWhere(function($q2) use ($filterStartDate, $filterEndDate) {
                  $q2->where('start_date', '<', $filterStartDate)
                     ->where('end_date', '>', $filterEndDate);
              });
        });
        
        // ✅ Extra filters for task_id / project_id
        if ($request->filled('task_id')) {
            // A task filter covers the task AND everything nested under it, so a parent's
            // diary shows the time logged on its sub-tasks too (same rule as Time logged).
            $query->whereIn('task_id', $this->taskTreeIds((int) $request->task_id));
        }
        
        if ($request->filled('project_id')) {
            $query->whereHas('taskDetail', function($q) use ($request) {
                $q->where('project_id', $request->project_id);
            });
        }



        $sessions = $query->orderBy('created_at', 'desc')->paginate(800);

        $overallTotalSeconds = 0;
        $time_strings_hr = [];

        foreach ($sessions as $session) {

            if (Auth::check() && !in_array(Auth::user()->user_role, [1,2])) {
                if ($session->screenshots) {
                    foreach ($session->screenshots as $shot) {
                        if (!empty($shot->emp_screenshot_file)) {
                            $shot->screenshot_file = $shot->emp_screenshot_file;
                        }
                    }
                }
            }

            $sessionStart = Carbon::parse($session->start_date . ' ' . $session->start_time);
            
            if (is_null($session->end_time)) {
                $sessionEnd = now();
                $session->total_time = 'Running';
            } else {
                $endDate = $session->end_date ?? $session->start_date;
                $sessionEnd = Carbon::parse($endDate . ' ' . $session->end_time);
            }

            // Initialize duration for the filtered date range
            $sessionDuration = 0;
            
            // Get all dates in the filter range
            $filterDates = [];
            $currentDate = Carbon::parse($filterStartDate);
            $endDateObj = Carbon::parse($filterEndDate);
            
            while ($currentDate->lte($endDateObj)) {
                $filterDates[] = $currentDate->toDateString();
                $currentDate->addDay();
            }

            // Calculate time for each day in filter range
            foreach ($filterDates as $date) {
                $dayStart = Carbon::parse($date)->startOfDay();
                $dayEnd = Carbon::parse($date)->endOfDay();
                
                // Get overlapping period between session and this specific day
                $workStart = max($sessionStart, $dayStart);
                $workEnd = min($sessionEnd, $dayEnd);
                
                if ($workStart->lt($workEnd)) {
                    // abs()+int: Carbon 3's diffInSeconds is signed (later->earlier is negative).
                    $sessionDuration += (int) abs($workEnd->diffInSeconds($workStart));
                }
            }

            // Calculate adjustments for the filtered period.
            $adjustmentSeconds = 0;
            $adjustments = DB::table('session_time_adjustments')
                ->where('session_id', $session->id)
                ->get();

            // MERGE overlapping idle intervals first so an overlap is never double-subtracted.
            $intervals = [];
            foreach ($adjustments as $adj) {
                if (empty($adj->start_time) || empty($adj->end_time)) {
                    continue;
                }
                $s = Carbon::parse($adj->start_time);
                $e = Carbon::parse($adj->end_time);
                if ($e->lte($s)) {
                    continue;
                }
                $intervals[] = [$s, $e];
            }
            usort($intervals, fn($a, $b) => $a[0]->getTimestamp() <=> $b[0]->getTimestamp());

            $merged = [];
            foreach ($intervals as $iv) {
                $n = count($merged);
                if ($n === 0 || $iv[0]->gt($merged[$n - 1][1])) {
                    $merged[] = $iv;
                } elseif ($iv[1]->gt($merged[$n - 1][1])) {
                    $merged[$n - 1][1] = $iv[1];
                }
            }

            foreach ($merged as [$adjStart, $adjEnd]) {
                // Clamp to the session's own window first, so a stale idle row can never
                // remove more time than the session actually contains.
                $adjStart = $adjStart->greaterThan($sessionStart) ? $adjStart->copy() : $sessionStart->copy();
                $adjEnd = $adjEnd->lessThan($sessionEnd) ? $adjEnd->copy() : $sessionEnd->copy();
                if ($adjEnd->lte($adjStart)) {
                    continue;
                }

                // Calculate adjustments day by day to respect midnight boundaries
                foreach ($filterDates as $date) {
                    $dayStart = Carbon::parse($date)->startOfDay();
                    $dayEnd = Carbon::parse($date)->endOfDay();

                    $adjStartFiltered = max($adjStart, $dayStart);
                    $adjEndFiltered = min($adjEnd, $dayEnd);

                    if ($adjStartFiltered->lt($adjEndFiltered)) {
                        $adjustmentSeconds += (int) abs($adjEndFiltered->diffInSeconds($adjStartFiltered));
                    }
                }
            }

            // Clamp at 0 and force integer (worked time is never negative; % 3600 needs an int).
            $netSeconds = (int) max(0, $sessionDuration - $adjustmentSeconds);

            // Expose the MERGED, session-clamped idle total so the UI never has to sum the raw
            // (possibly overlapping) idleTimes rows itself — that summing is what made displayed
            // idle exceed the worked time.
            $session->idle_seconds = $adjustmentSeconds;
            $session->idle_time_formatted = sprintf('%dh %dm', floor($adjustmentSeconds / 3600), floor(($adjustmentSeconds % 3600) / 60));

            if ($session->total_time !== 'Running') {
                $hours = floor($netSeconds / 3600);
                $minutes = floor(($netSeconds % 3600) / 60);
                $session->total_time = sprintf('%dh %dm', $hours, $minutes);
                $time_strings_hr[] = $session->total_time;
            }

            $session->raw_calculation = [
                'filter_range' => [$filterStartDate, $filterEndDate],
                'session_period' => [
                    'start' => $sessionStart->format('Y-m-d H:i:s'),
                    'end' => $sessionEnd->format('Y-m-d H:i:s')
                ],
                'session_duration' => $sessionDuration,
                'adjustments' => $adjustmentSeconds,
                'net_seconds' => $netSeconds
            ];

            if ($netSeconds > 0) {
                $overallTotalSeconds += $netSeconds;
            }
        }

        // Calculate total time string
        $totalMinutes = 0;
        foreach ($time_strings_hr as $time) {
            preg_match('/(\d+)h (\d+)m/', $time, $matches);
            if (count($matches) === 3) {
                $hours = (int)$matches[1];
                $minutes = (int)$matches[2];
                $totalMinutes += ($hours * 60) + $minutes;
            }
        }
        
        $totalHours = floor($totalMinutes / 60);
        $remainingMinutes = $totalMinutes % 60;
        $totalTimeString = "{$totalHours}h {$remainingMinutes}m";
        
         $ids = $sessions->pluck('id')->toArray();
        $windows_activity = $this->windowsActivity($ids);
        
        return response()->json(
            array_merge(
                $sessions->toArray(),
                [
                    'overall_total_time' => $totalTimeString,
                    'windows_activity' => $windows_activity
                ]
            )
        );
        
        
    } catch (\Exception $e) {
       // \Log::error('WorkSession Error: '.$e->getMessage());
        return response()->json([
            'error' => 'Server error',
            'message' => $e->getMessage()
        ], 500);
    }
}

    public function show($id){

        $data = WorkSession::with('screenshots','taskDetail','userDetail')
            ->findOrFail($id);

        return response()->json($data);
    }

    public function store(Request $request)
    {
        $userId = Auth::id();

        // 1. Check if any open session exists for this user
        $openSession = WorkSession::where('user_id', $userId)
            ->whereNull('end_time')
            ->latest('start_time')
            ->first();

        // 2. Close the previous open session (if exists)
        if ($openSession) {
            $openSession->end_time = Carbon::now();
            $openSession->save();
        }

         $now = Carbon::now();

        // 3. Start a new session
        $newSession = new WorkSession();
        $newSession->user_id = $userId;
        $newSession->task_id = $request->task_id;
        $newSession->memo_content = $request->memo_content;
        $newSession->start_time = $now; // explicitly setting start time
        $newSession->start_date = $now->toDateString(); // explicitly setting start time
        $newSession->save();

        return response()->json([
            'message' => 'Work session started successfully.',
            'work_session' => $newSession
        ]);
    }

    public function stop(Request $request)
    {
        $userId = Auth::id();

        // Find the latest open session
        $openSession = WorkSession::where('user_id', $userId)
            ->whereNull('end_time')
            ->latest('start_time')
            ->first();

        if (!$openSession) {
            return response()->json([
                'message' => 'No active work session found to stop.'
            ], 404);
        }

        // Close the session by setting end_time and end_date
        $now = Carbon::now();
        $openSession->end_time = $now;
        $openSession->end_date = $now->toDateString(); // Sets just the date portion (YYYY-MM-DD)
        $openSession->save();

        return response()->json([
            'message' => 'Work session stopped successfully.',
            'work_session' => $openSession
        ]);
    }

    public function destroy($id)
    {
        $task = WorkSession::findOrFail($id);
        $task->delete();

        return response()->json(['message' => 'Work session deleted successfully.']);
    }

    /**
     * Add manual time ON BEHALF of another employee (POST .../other-manual-time).
     *
     * Reachable from the admin and employee route groups, so it checks WHO is
     * asking instead of trusting the route: only admins and managers /
     * supervisors / executives may log time for someone else — a plain employee
     * could otherwise post any user_id here. The entry is stored as type
     * "Manual", like an employee's own manual time, so it is labelled as such in
     * every diary.
     */
    public function manualSession(Request $request)
    {
        $actor = Auth::user();
        $mayAddForOthers = $actor && (
            in_array((int) $actor->user_role, [2, 6, 7], true)
            || in_array($actor->employee_type, ['Manager', 'Supervisor', 'Executive'], true)
        );

        if (!$mayAddForOthers) {
            return response()->json(['message' => 'You are not allowed to add time for other employees.'], 403);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'task_id' => 'required|integer|exists:project_tasks,id',
            'start_date' => 'required|date',
            'start_time' => 'required|date_format:H:i,H:i:s',
            'end_date' => 'required|date',
            'end_time' => 'required|date_format:H:i,H:i:s',
            'memo_content' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $employee = User::find($request->user_id);
        if (!$employee || (int) $employee->user_role !== 3) {
            return response()->json(['message' => 'Time can only be added for an employee.'], 422);
        }

        $start = Carbon::parse($request->start_date . ' ' . $request->start_time);
        $end = Carbon::parse($request->end_date . ' ' . $request->end_time);
        if ($end->lessThanOrEqualTo($start)) {
            return response()->json(['message' => 'End time must be after the start time.'], 422);
        }

        $data = new WorkSession();
        $data->user_id = $request->user_id;
        $data->start_date = $request->start_date;
        $data->start_time = $request->start_time;
        $data->end_date = $request->end_date;
        $data->end_time = $request->end_time;
        $data->memo_content = $request->memo_content;
        $data->task_id = $request->task_id;
        $data->type = 'Manual';
        $data->save();

        return response()->json([
            'message' => 'Manual time added successfully.',
            'work_session' => $data
        ]);

    }


}
