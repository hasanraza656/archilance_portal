<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\WorkSession;
use App\Traits\CalculatesIdleTime;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * GET /employee/work-pulse
 *
 * Raw material for the time tracker's "how is your week going" banner: net
 * worked seconds per calendar day for last week + this week so far, plus the
 * days the person is on approved leave. Deliberately data-only — the wording is
 * composed in the tracker, which also knows the company holiday calendar.
 *
 * Always scoped to the signed-in user (no id parameter), so unlike the
 * work-diary average endpoint there is nothing to guard against.
 */
class WorkPulseController extends Controller
{
    use CalculatesIdleTime;

    public function show()
    {
        $user = Auth::user();

        $today = Carbon::today();
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        $lastWeekStart = $weekStart->copy()->subWeek();

        $sessions = WorkSession::where('user_id', $user->id)
            ->whereBetween('start_date', [$lastWeekStart->toDateString(), $today->toDateString()])
            ->get(['id', 'start_date', 'start_time', 'end_date', 'end_time']);

        $adjustments = $sessions->isEmpty()
            ? collect()
            : DB::table('session_time_adjustments')
                ->whereIn('session_id', $sessions->pluck('id'))
                ->get(['session_id', 'start_time', 'end_time'])
                ->groupBy('session_id');

        // Every day in range starts at zero so the client gets a dense list.
        $perDay = [];
        for ($d = $lastWeekStart->copy(); $d->lte($today); $d->addDay()) {
            $perDay[$d->toDateString()] = 0;
        }

        $now = Carbon::now();

        foreach ($sessions as $session) {
            try {
                $start = Carbon::parse($session->start_date . ' ' . $session->start_time);
            } catch (\Throwable $e) {
                continue;
            }

            if (empty($session->end_time)) {
                // Still running only counts if it started today; an old unclosed
                // session would otherwise inflate a past day with days of "work".
                if ($start->toDateString() !== $today->toDateString()) {
                    continue;
                }
                $end = $now;
            } else {
                try {
                    $end = Carbon::parse(($session->end_date ?: $session->start_date) . ' ' . $session->end_time);
                } catch (\Throwable $e) {
                    continue;
                }
            }

            $day = $start->toDateString();
            if (!array_key_exists($day, $perDay)) {
                continue;
            }

            $perDay[$day] += $this->sessionWorkedSeconds($adjustments->get($session->id, collect()), $start, $end);
        }

        return response()->json([
            'today' => $today->toDateString(),
            'weekday' => (int) $today->dayOfWeekIso, // 1 = Monday … 7 = Sunday
            'week_start' => $weekStart->toDateString(),
            'last_week_start' => $lastWeekStart->toDateString(),
            'days' => collect($perDay)
                ->map(fn ($seconds, $date) => ['date' => $date, 'worked_seconds' => (int) $seconds])
                ->values(),
            'leave_dates' => $this->leaveDates($user->id, $lastWeekStart, $today),
        ]);
    }

    /** Weekday dates inside [from, to] covered by an approved leave request. */
    private function leaveDates(int $userId, Carbon $from, Carbon $to): array
    {
        $leaves = LeaveRequest::where('user_id', $userId)
            ->where('status', 'Approved')
            ->where('start_date', '<=', $to->toDateString())
            ->where('end_date', '>=', $from->toDateString())
            ->get(['start_date', 'end_date']);

        $dates = [];
        foreach ($leaves as $leave) {
            try {
                $cursor = Carbon::parse($leave->start_date)->startOfDay();
                $last = Carbon::parse($leave->end_date)->startOfDay();
            } catch (\Throwable $e) {
                continue;
            }

            for (; $cursor->lte($last); $cursor->addDay()) {
                if ($cursor->lt($from) || $cursor->gt($to) || $cursor->isWeekend()) {
                    continue;
                }
                $dates[$cursor->toDateString()] = true;
            }
        }

        return array_keys($dates);
    }
}
