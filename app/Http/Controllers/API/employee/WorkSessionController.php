<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Screenshot;
use App\Models\WorkSession;
use Auth;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\DB;
use App\Models\TrackWindow;
use App\Models\WorkingHour;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Schema;
use App\Traits\ResolvesClientTime;
use App\Traits\BuildsWindowActivity;
use App\Traits\ResolvesTaskTree;


class WorkSessionController extends Controller
{
    use BuildsWindowActivity;
    use ResolvesTaskTree;
    use ResolvesClientTime;


    public function index(Request $request)
    {
        try {
            $userId = Auth::id();

            $query = WorkSession::with('screenshots', 'taskDetail', 'userDetail', 'idleTimes')
                ->where('user_id', $userId);

            // Use current date if no dates provided
            $filterStartDate = $request->start_date ?? now()->toDateString();
            $filterEndDate = $request->end_date ?? $filterStartDate;

            // Date filters
            $query->where(function ($q) use ($filterStartDate, $filterEndDate) {
                $q->whereBetween('start_date', [$filterStartDate, $filterEndDate])
                    ->orWhereBetween('end_date', [$filterStartDate, $filterEndDate])
                    ->orWhere(function ($q2) use ($filterStartDate, $filterEndDate) {
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
                $query->whereHas('taskDetail', function ($q) use ($request) {
                    $q->where('project_id', $request->project_id);
                });
            }



        $sessions = $query->orderBy('created_at', 'desc')->paginate(400);

            $overallTotalSeconds = 0;
            $time_strings_hr = [];

            foreach ($sessions as $session) {
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
                        // abs()+int: Carbon 3's diffInSeconds is signed, so later->earlier is
                        // NEGATIVE. Force a positive integer (matching the adjustment term and
                        // User::calculateWorkedTime) — otherwise every completed session, whose
                        // net is then clamped by max(0,...), would display as 0h 0m.
                        $sessionDuration += (int) abs($workEnd->diffInSeconds($workStart));
                    }
                }

                // Calculate adjustments for the filtered period.
                $adjustmentSeconds = 0;
                $adjustments = DB::table('session_time_adjustments')
                    ->where('session_id', $session->id)
                    ->get();

                // MERGE overlapping idle intervals FIRST. Two idle records can legitimately
                // overlap (e.g. a real-time idle that overlaps a sleep-gap idle); summing each
                // one independently would double-subtract the overlap and drive worked time
                // negative. Union them so each real second of idle is counted exactly once.
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
                            // abs()+int: Carbon 3's diffInSeconds is signed/float; force a
                            // non-negative integer so accumulation and the later % are correct.
                            $adjustmentSeconds += (int) abs($adjEndFiltered->diffInSeconds($adjStartFiltered));
                        }
                    }
                }

                // Clamp at 0 and force integer — worked time can never be negative, and the
                // hours/minutes math below relies on an int (% 3600). (Previously used abs(),
                // which turned a double-subtract error into a bogus POSITIVE total.)
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
                    $hours = (int) $matches[1];
                    $minutes = (int) $matches[2];
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
            \Log::error('WorkSession Error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Server error',
                'message' => $e->getMessage()
            ], 500);
        }
    }


    public function show($id)
    {

        $data = WorkSession::with('screenshots', 'taskDetail', 'userDetail')
            ->findOrFail($id);

        return response()->json($data);
    }

   public function store(Request $request)
    {
        $userId = Auth::id();

        $my_working_hours = WorkingHour::where('employee_id', $userId)->get();

        // Legacy per-user clock offset — used ONLY as a fallback when the client does not
        // send an absolute UTC timestamp (older app builds).
        if ($userId == 173 || $userId == 146) {
            $now = Carbon::now()->subHours(2);
        } elseif ($userId == 182) {
            $now = Carbon::now()->addHours(2);
        } else {
            $now = Carbon::now();
        }

        // Resolve the REAL start instant:
        //  - Prefer start_utc (absolute → correct across timezones AND offline delay); no hack.
        //  - Else legacy local start_time (+ per-user hack) for older clients.
        //  - Else server now().
        if ($this->hasClientUtc($request->input('start_utc'))) {
            $startDateTime = $this->resolveClientUtc($request->input('start_utc'), $now);
        } elseif ($request->filled('start_time')) {
            try {
                $legacyStart = Carbon::parse($request->start_time);
                if ($userId == 173 || $userId == 146) {
                    $legacyStart = $legacyStart->subHours(2);
                } elseif ($userId == 182) {
                    $legacyStart = $legacyStart->addHours(2);
                }
                $startDateTime = $legacyStart;
            } catch (\Throwable $e) {
                $startDateTime = $now->copy();
            }
        } else {
            $startDateTime = $now->copy();
        }

        // 1. Working-hours restriction — checked against the ACTUAL start instant so a session
        //    started within hours (but synced later while offline) is not wrongly rejected.
        if ($my_working_hours->count() > 0) {
            $withinWorkingHours = false;
            $checkTime = Carbon::parse($startDateTime->format('H:i:s'));
            foreach ($my_working_hours as $slot) {
                $slotStart = Carbon::parse($slot->start_time);
                $slotEnd = Carbon::parse($slot->end_time);
                if ($checkTime->between($slotStart, $slotEnd)) {
                    $withinWorkingHours = true;
                    break;
                }
            }

            if (!$withinWorkingHours) {
                return response()->json([
                    'error' => 'You cannot start a work session outside your working hours.',
                    'error_code' => "working_hours_restriction"
                ], 404);
            }
        }
        // If no working hours defined, allow starting anytime.

        // 2-4. Close any open session and create the new one ATOMICALLY, so two concurrent
        //      starts (or a start racing another request) can never leave two open sessions.
        $newSession = DB::transaction(function () use ($userId, $request, $startDateTime) {
            $openSession = WorkSession::where('user_id', $userId)
                ->whereNull('end_time')
                ->lockForUpdate()
                ->latest('start_time')
                ->first();

            if ($openSession) {
                $lastScreenshot = Screenshot::where('session_id', $openSession->id)
                    ->latest('created_at')
                    ->first();

                if ($lastScreenshot) {
                    $adjustedTime = Carbon::parse($lastScreenshot->created_at);
                    $openSession->end_time = $adjustedTime;
                    $openSession->end_date = $adjustedTime->toDateString();
                } else {
                    $openSession->end_time = Carbon::now();
                    $openSession->end_date = Carbon::now()->toDateString();
                }

                $openSession->save();
            }

            $session = new WorkSession();
            $session->user_id = $userId;
            $session->task_id = $request->task_id;
            $session->memo_content = $request->memo_content;
            $session->start_time = $startDateTime;
            // Derive start_date from the same instant so date + time can never disagree.
            $session->start_date = $startDateTime->toDateString();
            $session->save();

            return $session;
        });

        return response()->json([
            'message' => 'Work session started successfully. Previous session closed if it was open.',
            'work_session' => $newSession
        ]);
    }




    public function stop(Request $request)
    {
        $userId = Auth::id();

        // Set when the session we are stopping was closed by the CheckHeartBeat
        // watchdog rather than by a person. That close is a GUESS (a sleeping
        // laptop looks exactly like a finished day), so an explicit stop arriving
        // afterwards is better information and is allowed to extend it. A session
        // a user really stopped is never touched — see the guard below.
        $wasProvisional = false;

        // If work_session_id is provided, stop that specific session.
        // This prevents stale queued stop-actions from closing a later active session.
        if ($request->filled('work_session_id')) {
            $openSession = WorkSession::where('user_id', $userId)
                ->where('id', $request->work_session_id)
                ->first();

            if (!$openSession) {
                return response()->json(['message' => 'Work session not found.'], 404);
            }

            if (!is_null($openSession->end_time)) {
                if (!$this->isProvisionallyClosed($openSession)) {
                    // Genuinely stopped already — accept gracefully so the action
                    // queue can move on. Unchanged behaviour.
                    return response()->json([
                        'message' => 'Work session already stopped.',
                        'work_session' => $openSession
                    ]);
                }

                $wasProvisional = true;
            }
        } else {
            // Fallback: stop the latest open session (legacy / web path)
            $openSession = WorkSession::where('user_id', $userId)
                ->whereNull('end_time')
                ->latest('start_time')
                ->first();

            // Older clients don't send work_session_id. If the watchdog closed
            // their session while they were still working, there is no open row to
            // find and their stop used to vanish silently — taking every hour
            // since the auto-close with it. Fall back to that provisional session.
            if (!$openSession) {
                $openSession = $this->latestProvisionallyClosedSession($userId);
                $wasProvisional = (bool) $openSession;
            }

            if (!$openSession) {
                return response()->json([
                    'message' => 'No active work session found to stop.'
                ], 404);
            }
        }

        // Determine end time.
        //  - Prefer end_utc (absolute → correct across timezones AND offline delay).
        //  - Else legacy local end_time / end_date for older clients.
        //  - Else server now().
        $now = Carbon::now();
        if ($this->hasClientUtc($request->input('end_utc'))) {
            $sessionEndTime = $this->resolveClientUtc($request->input('end_utc'), $now);
            $sessionEndDate = $sessionEndTime->toDateString();
        } else {
            $sessionEndTime = $request->filled('end_time')
                ? Carbon::parse($request->end_time)
                : $now;

            $sessionEndDate = $request->filled('end_date')
                ? Carbon::parse($request->end_date)->toDateString()
                : $now->toDateString();
        }

        // ------------------------------------------------------------------
        // EXTENDING A PROVISIONALLY-CLOSED SESSION
        // ------------------------------------------------------------------
        // Only reached when the watchdog closed this session and the app has now
        // come back to stop it properly. Four guards, each closing a way this
        // could over-credit time:
        if ($wasProvisional) {
            $clientGaveTime = $this->hasClientUtc($request->input('end_utc'))
                || $request->filled('end_time');

            // The legacy path carries the date in $sessionEndDate and the time in
            // $sessionEndTime SEPARATELY — and Carbon::parse('02:23:00') dates that
            // time TODAY. Recombine into one absolute instant before comparing
            // anything, or every guard below compares against the wrong day.
            $proposedEnd = Carbon::parse($sessionEndDate . ' ' . $sessionEndTime->toTimeString());

            $currentEnd = $this->sessionEndAt($openSession);

            // 1. No client timestamp → do NOT fall back to now(). A stop that sat
            //    in the offline queue for hours would otherwise bill every one of
            //    them. Use the last moment we can PROVE the app was alive instead;
            //    and when there is no proof at all (no heartbeat, no screenshot),
            //    leave the recorded end exactly where it is rather than reaching
            //    for now() — that would be the very over-crediting this guards.
            if (!$clientGaveTime) {
                $proposedEnd = $this->lastProvenActivityAt($openSession)
                    ?: ($currentEnd ?: $proposedEnd);
            }

            // 2. Never move an end backwards.
            if ($currentEnd && $proposedEnd->lessThan($currentEnd)) {
                $proposedEnd = $currentEnd;
            }

            // 3. Never grow past the start of a later session, which would make two
            //    sessions overlap and double-count the overlap in every report.
            $nextStart = $this->nextSessionStartAfter($openSession);
            if ($nextStart && $proposedEnd->greaterThan($nextStart)) {
                $proposedEnd = $nextStart;
            }

            // 4. Never end in the future (a client clock running fast).
            if ($proposedEnd->greaterThan($now)) {
                $proposedEnd = $now;
            }

            $sessionEndTime = $proposedEnd;
            $sessionEndDate = $proposedEnd->toDateString();

            // The guess has been replaced by a real stop, so it is final now and
            // must never be extended again.
            if ($this->supportsAutoClose()) {
                $openSession->auto_closed_at = null;
            }
        }

        // ----------------------------------------------
        // CLOSE ANY ACTIVE TRACK WINDOW FOR THIS SESSION
        // ----------------------------------------------
        $openWindow = TrackWindow::where('employee_id', $userId)
            ->where('session_id', $openSession->id)
            ->whereNull('end_time')
            ->latest('start_time')
            ->first();

        if ($openWindow) {

            // Set end_time same as WorkSession end_time
            $openWindow->end_time = $sessionEndTime;

            // Calculate duration
            $start = Carbon::parse($openWindow->start_time);
            $end = Carbon::parse($sessionEndTime);

            $openWindow->duration_seconds = $start->diffInSeconds($end);

            $openWindow->save();
        }

        // ----------------------------------------------
        // CLOSE WORK SESSION
        // ----------------------------------------------
        $openSession->end_time = $sessionEndTime;
        $openSession->end_date = $sessionEndDate;
        $openSession->save();

        return response()->json([
            'message' => 'Work session stopped successfully.',
            'work_session' => $openSession
        ]);
    }

    /* ------------------------------------------------------------------
     | Provisional-close helpers
     |
     | A session closed by CheckHeartBeat carries `auto_closed_at`; one the
     | user stopped does not. Every method here is guarded by
     | Schema::hasColumn so the controller still works if the code is
     | deployed before the migration has been run.
     ------------------------------------------------------------------ */

    /**
     * Whether the auto_closed_at column exists yet. Memoised per request:
     * Schema::hasColumn hits information_schema every call, and stop() would
     * otherwise ask two or three times for an answer that cannot change.
     */
    private static ?bool $supportsAutoClose = null;

    private function supportsAutoClose(): bool
    {
        if (self::$supportsAutoClose === null) {
            self::$supportsAutoClose = Schema::hasColumn('work_sessions', 'auto_closed_at');
        }

        return self::$supportsAutoClose;
    }

    /** True when the watchdog closed this session, not a person. */
    private function isProvisionallyClosed(WorkSession $session): bool
    {
        return $this->supportsAutoClose() && !is_null($session->auto_closed_at);
    }

    /** The user's most recent watchdog-closed session, for clients that send no id. */
    private function latestProvisionallyClosedSession($userId): ?WorkSession
    {
        if (!$this->supportsAutoClose()) {
            return null;
        }

        return WorkSession::where('user_id', $userId)
            ->whereNotNull('auto_closed_at')
            ->whereNotNull('end_time')
            ->latest('start_time')
            ->first();
    }

    /** A session's current end as an absolute instant, or null if still open. */
    private function sessionEndAt(WorkSession $session): ?Carbon
    {
        if (is_null($session->end_time)) {
            return null;
        }

        try {
            return Carbon::parse(($session->end_date ?? $session->start_date) . ' ' . $session->end_time);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The latest moment we have PROOF the app was alive for this session —
     * the newer of its last heartbeat and its last screenshot. Used instead of
     * now() when a stop arrives without a client timestamp, so a long-queued
     * stop cannot bill the hours it spent sitting in the queue.
     */
    private function lastProvenActivityAt(WorkSession $session): ?Carbon
    {
        $best = null;

        foreach ([$session->last_heartbeat, Screenshot::where('session_id', $session->id)->max('created_at')] as $value) {
            if (empty($value)) {
                continue;
            }
            try {
                $at = Carbon::parse($value);
            } catch (\Throwable $e) {
                continue;
            }
            if (is_null($best) || $at->greaterThan($best)) {
                $best = $at;
            }
        }

        return $best;
    }

    /** Start of this user's next session after the given one, if any. */
    private function nextSessionStartAfter(WorkSession $session): ?Carbon
    {
        $startedAt = $session->start_date . ' ' . $session->start_time;

        $next = WorkSession::where('user_id', $session->user_id)
            ->where('id', '!=', $session->id)
            ->whereRaw("CONCAT(start_date, ' ', start_time) > ?", [$startedAt])
            ->orderByRaw("CONCAT(start_date, ' ', start_time) ASC")
            ->first();

        if (!$next) {
            return null;
        }

        try {
            return Carbon::parse($next->start_date . ' ' . $next->start_time);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function destroy($id)
    {
        $task = WorkSession::findOrFail($id);
        $task->delete();

        return response()->json(['message' => 'Work session deleted successfully.']);
    }

    public function manualSession(Request $request)
    {

        $data = new WorkSession();
        $data->user_id = Auth::user()->id;
        $data->start_date = $request->start_date;
        $data->start_time = $request->start_time;
        $data->end_date = $request->end_date;
        $data->end_time = $request->end_time;
        $data->memo_content = $request->memo_content;
        $data->task_id = $request->task_id;
        $data->type = "Manual";
        $data->save();

        return response()->json([
            'message' => 'Manual time added successfully.',
            'work_session' => $data
        ]);

    }




   public function sessionHeartBeat(Request $request)
    {
        // ✅ Validate request
        $validator = Validator::make($request->all(), [
            'session_id' => 'required|exists:work_sessions,id',
            'type' => 'nullable|string|max:255',
    
            // Optional activity chunks (NEW SYSTEM)
            'activities' => 'nullable|array|max:500',
            'activities.*.start_time' => 'required_with:activities|date',
            'activities.*.end_time' => 'required_with:activities|date',
            'activities.*.keyboard_clicks' => 'nullable|integer',
            'activities.*.mouse_clicks' => 'nullable|integer',
            'activities.*.is_idle' => 'nullable|boolean',
            'activities.*.active_window_title' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid data',
                'errors' => $validator->errors(),
            ], 422);
        }
    
        DB::beginTransaction();
    
        try {
            // ✅ Fetch session
            $session = WorkSession::find($request->session_id);
    
            if (!$session) {
                return response()->json([
                    'status' => false,
                    'message' => 'Session not found',
                ], 404);
            }
    
            // =====================================================
            // 🔹 HEARTBEAT UPDATE (OLD SYSTEM SAFE)
            // =====================================================
            
                $userId = Auth::user()->id;

            // last_heartbeat is a LIVENESS signal — "we are hearing from this app right now".
            // It MUST be real server time (config('app.timezone')) so it is directly comparable
            // to CheckHeartBeat's cutoff of now()->subMinutes(20).
            //
            // We deliberately do NOT use:
            //   - the per-user +/-2h clock hack (it would push last_heartbeat 2h into the past,
            //     making the every-minute CheckHeartBeat close a perfectly active session for
            //     users 173/146), nor
            //   - a queued pulse's original timestamp (a heartbeat that reaches us now proves the
            //     app is alive now; using a >20-min-old pulse time would close it immediately).
            $updateData = [
                'last_heartbeat' => Carbon::now(),
                'last_heartbeat_type' => $request->type ?? 'Active',
            ];

            // NOTE: We intentionally do NOT reactivate an ended session here.
            // Re-opening a session that was already closed (by an explicit stop or by the
            // CheckHeartBeat safety-net) caused it to ping-pong open/closed and overlap with
            // newer sessions, corrupting the timeline. A closed session stays closed; the
            // heartbeat only records liveness via last_heartbeat.

            $session->update($updateData);
    
            // =====================================================
            // 🔥 ACTIVITY LOGGING (NEW SYSTEM)
            // =====================================================
            if (!empty($request->activities)) {
    
                $insertData = [];
    
                foreach ($request->activities as $activity) {
                    $insertData[] = [
                        'employee_id' => Auth::user()->id,
                        'session_id' => $session->id,
    
                        'start_time' => $activity['start_time'],
                        'end_time' => $activity['end_time'],
    
                        'keyboard_clicks' => $activity['keyboard_clicks'] ?? 0,
                        'mouse_clicks' => $activity['mouse_clicks'] ?? 0,
    
                        'is_idle' => $activity['is_idle'] ?? false,
                        'active_window_title' => $activity['active_window_title'] ?? null,
    
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
    
                // 🚀 Bulk insert for performance
                ActivityLog::insert($insertData);
            }
    
            DB::commit();
    
            return response()->json([
                'status' => true,
                'message' => 'Heartbeat processed successfully',
                'data' => [
                    'session_id' => $session->id,
                    'activity_logged' => !empty($request->activities),
                    'chunks_received' => count($request->activities ?? []),
                ],
            ]);
    
        } catch (\Exception $e) {
            DB::rollBack();
    
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function fetchActivityLogs($id)
    {
        // ✅ Validate ID
        if (!$id || !is_numeric($id)) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid session ID',
            ], 422);
        }
    
        // ✅ Fetch logs
        $logs = ActivityLog::where('session_id', $id)
            ->orderBy('start_time', 'asc')
            ->get();
    
        // ✅ Handle empty data
        if ($logs->isEmpty()) {
            return response()->json([
                'status' => true,
                'message' => 'No activity logs found',
                'data' => [],
            ]);
        }
    
        return response()->json([
            'status' => true,
            'message' => 'Success',
            'data' => $logs
        ]);
    }


}
