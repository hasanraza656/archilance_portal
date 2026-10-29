<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WorkSession;
use App\Support\PayrollSchema;
use App\Traits\CalculatesIdleTime;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Working Hours and Salary Policy: an employee who records less than 50% of the required working hours in a calendar
 * month, without a genuine reason, may have that month's salary withheld after HR has looked at the case.
 *
 * This only INFORMS: it works out, for each person in a payroll, how many hours were required and how many were
 * recorded (idle time excluded, like every other hours figure), and flags anyone below the threshold so finance and HR
 * can review before paying. Nothing is ever held back automatically.
 *
 * Required hours = (working days Mon-Fri in the part of the month the person was employed, minus days of approved
 * leave) x hours per day (8 by default, PAYROLL_REQUIRED_HOURS_PER_DAY). Approved leave counts as a genuine reason.
 */
class WorkHoursPolicy
{
    use CalculatesIdleTime;

    public const THRESHOLD = 0.5;

    public static function hoursPerDay(): float
    {
        $v = (float) env('PAYROLL_REQUIRED_HOURS_PER_DAY', 8);

        return $v > 0 ? $v : 8.0;
    }

    /** True once the month is over, so the recorded hours are the final figure. */
    public static function monthComplete(Carbon $month): bool
    {
        return Carbon::today()->gt($month->copy()->endOfMonth());
    }

    /**
     * @param  int[]  $userIds  real employee ids (service providers have no tracker)
     * @return array<int, array{required:float,worked:float,pct:?int,leave_days:int,low:bool}>
     */
    public function forMonth(array $userIds, Carbon $month): array
    {
        $userIds = array_values(array_filter(array_map('intval', $userIds), fn ($id) => $id > 0));
        if (!$userIds) {
            return [];
        }

        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth()->startOfDay();
        $perDay = self::hoursPerDay();

        $users = User::withTrashed()->whereIn('id', $userIds)->get()->keyBy('id');
        $generator = app(PayrollGenerator::class);

        // Hours recorded in the month, idle removed.
        $worked = array_fill_keys($userIds, 0);
        $sessions = WorkSession::whereIn('user_id', $userIds)
            ->whereDate('start_date', '>=', $start->toDateString())
            ->whereDate('start_date', '<=', $end->toDateString())
            ->get(['id', 'user_id', 'start_date', 'start_time', 'end_date', 'end_time']);

        $adjustments = [];
        foreach (array_chunk($sessions->pluck('id')->all(), 800) as $ids) {
            foreach (DB::table('session_time_adjustments')->whereIn('session_id', $ids)->get(['session_id', 'start_time', 'end_time']) as $a) {
                $adjustments[$a->session_id][] = $a;
            }
        }
        foreach ($sessions as $s) {
            try {
                $from = Carbon::parse($s->start_date . ' ' . $s->start_time);
                $to = is_null($s->end_time) ? now() : Carbon::parse(($s->end_date ?? $s->start_date) . ' ' . $s->end_time);
                $worked[$s->user_id] += $this->sessionWorkedSeconds($adjustments[$s->id] ?? [], $from, $to);
            } catch (\Throwable $e) {
                continue;
            }
        }

        // Approved leave in the month (any type: it is a genuine reason).
        $leave = [];
        if (PayrollSchema::table('leave_requests')) {
            foreach (LeaveRequest::whereIn('user_id', $userIds)->where('status', 'Approved')
                ->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->get(['user_id', 'start_date', 'end_date']) as $l) {
                $leave[$l->user_id][] = [Carbon::parse($l->start_date)->startOfDay(), Carbon::parse($l->end_date)->startOfDay()];
            }
        }

        $out = [];
        foreach ($userIds as $id) {
            $u = $users->get($id);
            if (!$u) {
                continue;
            }

            $first = $start->copy();
            if ($u->joining_date) {
                $j = Carbon::parse($u->joining_date)->startOfDay();
                if ($j->gt($first)) {
                    $first = $j;
                }
            }
            $last = $end->copy();
            $left = $generator->lastDay($u);
            if ($left && $left->lt($last)) {
                $last = $left;
            }

            $days = 0;
            $leaveDays = 0;
            $covered = $leave[$id] ?? [];
            for ($d = $first->copy(); $d->lte($last); $d->addDay()) {
                if (!$d->isWeekday()) {
                    continue;
                }
                $onLeave = false;
                foreach ($covered as [$a, $b]) {
                    if ($d->betweenIncluded($a, $b)) {
                        $onLeave = true;
                        break;
                    }
                }
                if ($onLeave) {
                    $leaveDays++;
                } else {
                    $days++;
                }
            }

            $required = round($days * $perDay, 1);
            $hours = round(($worked[$id] ?? 0) / 3600, 1);
            $out[$id] = [
                'required' => $required,
                'worked' => $hours,
                'pct' => $required > 0 ? (int) round($hours / $required * 100) : null,
                'leave_days' => $leaveDays,
                'low' => $required > 0 && $hours < $required * self::THRESHOLD,
            ];
        }

        return $out;
    }
}
