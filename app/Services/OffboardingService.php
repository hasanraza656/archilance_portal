<?php

namespace App\Services;

use App\Models\Offboarding;
use App\Models\OffboardingLog;
use App\Models\PayrollRun;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Support\NoticePolicy;
use App\Support\PayrollSchema;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Offboarding: schedule someone's departure, keep payroll in step with it, and switch their account off on the right day.
 *
 *  - Scheduling writes the last working day to users.hire_end_date straight away (payroll reads the offboarding record).
 *  - The account (soft delete) is removed on `removal_date`: immediately if that is today or earlier, otherwise by the
 *    daily `offboarding:process` job (and also whenever the offboarding screen is opened, so a missed cron run catches up).
 *  - Nothing is ever hard-deleted, and every step is written to the history. A scheduled offboarding can be cancelled,
 *    and a removed account can be reinstated.
 */
class OffboardingService
{
    public function log(Offboarding $o, string $action, ?string $detail = null, ?int $actorId = null): void
    {
        try {
            OffboardingLog::create([
                'offboarding_id' => $o->id,
                'actor_id' => $actorId ?? Auth::id(),
                'action' => $action,
                'detail' => $detail !== null ? mb_substr($detail, 0, 1000) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // history is best-effort
        }
    }

    private function setHireEnd(int $userId, ?string $date): void
    {
        if (PayrollSchema::has('users', 'hire_end_date')) {
            DB::table('users')->where('id', $userId)->update(['hire_end_date' => $date]);
        }
    }

    /** @param array{reason_type?:string,reason?:?string,last_working_day:string,removal_date:string} $data */
    public function schedule(User $user, array $data, ?int $actorId = null): Offboarding
    {
        $actorId = $actorId ?? Auth::id();

        $o = DB::transaction(function () use ($user, $data, $actorId) {
            $previousEnd = PayrollSchema::has('users', 'hire_end_date') && $user->hire_end_date
                ? Carbon::parse($user->hire_end_date)->toDateString()
                : null;

            $row = [
                'user_id' => $user->id,
                'status' => Offboarding::SCHEDULED,
                'reason_type' => $data['reason_type'] ?? 'Terminated',
                'reason' => $data['reason'] ?? null,
                'last_working_day' => $data['last_working_day'],
                'removal_date' => $data['removal_date'],
                'previous_hire_end_date' => $previousEnd,
                'employee_name' => $user->name,
                'employee_email' => $user->email,
                'employee_type' => $user->employee_type,
                'employee_team' => $user->employee_team,
                'joining_date' => $user->joining_date ? Carbon::parse($user->joining_date)->toDateString() : null,
                'initiated_by' => $actorId,
            ];
            if (PayrollSchema::has('offboardings', 'notice_date')) {
                $row['notice_date'] = Carbon::parse($data['notice_date'] ?? today())->toDateString();
            }
            $o = Offboarding::create($row);

            $this->setHireEnd($user->id, Carbon::parse($data['last_working_day'])->toDateString());
            $this->log($o, 'created', $o->reason_type . ' · last working day ' . $o->last_working_day->format('j M Y') . ' · account removed ' . $o->removal_date->format('j M Y'), $actorId);

            return $o;
        });

        Offboarding::flush();

        return $this->removeIfDue($o->fresh(), $actorId);
    }

    /** Change dates / reason of a scheduled offboarding. */
    public function update(Offboarding $o, array $data, ?int $actorId = null): Offboarding
    {
        $before = ['last' => $o->last_working_day->toDateString(), 'removal' => $o->removal_date->toDateString(), 'reason' => $o->reason_type, 'notice' => $o->notice_date?->toDateString()];

        DB::transaction(function () use ($o, $data, $actorId, $before) {
            $o->fill(array_filter([
                'reason_type' => $data['reason_type'] ?? null,
                'last_working_day' => $data['last_working_day'] ?? null,
                'removal_date' => $data['removal_date'] ?? null,
            ], fn ($v) => $v !== null));
            if (array_key_exists('reason', $data)) {
                $o->reason = $data['reason'];
            }
            if (!empty($data['notice_date']) && PayrollSchema::has('offboardings', 'notice_date')) {
                $o->notice_date = Carbon::parse($data['notice_date'])->toDateString();
            }
            $o->save();
            $o->refresh();

            $this->setHireEnd($o->user_id, $o->last_working_day->toDateString());

            $parts = [];
            if ($before['last'] !== $o->last_working_day->toDateString()) {
                $parts[] = 'last working day ' . Carbon::parse($before['last'])->format('j M Y') . ' → ' . $o->last_working_day->format('j M Y');
            }
            if ($before['removal'] !== $o->removal_date->toDateString()) {
                $parts[] = 'account removal ' . Carbon::parse($before['removal'])->format('j M Y') . ' → ' . $o->removal_date->format('j M Y');
            }
            if (($before['notice'] ?? null) !== $o->notice_date?->toDateString() && $o->notice_date) {
                $parts[] = 'notice given on ' . $o->notice_date->format('j M Y');
            }
            if ($before['reason'] !== $o->reason_type) {
                $parts[] = 'reason ' . $before['reason'] . ' → ' . $o->reason_type;
            }
            $this->log($o, 'changed', $parts ? implode('; ', $parts) : 'Details updated', $actorId);
        });

        Offboarding::flush();

        return $this->removeIfDue($o->fresh(), $actorId);
    }

    public function removeIfDue(Offboarding $o, ?int $actorId = null): Offboarding
    {
        if ($o->status === Offboarding::SCHEDULED && $o->removal_date->lte(Carbon::today())) {
            return $this->removeAccount($o, $actorId, 'removed');
        }

        return $o;
    }

    /** Switches the account off now (soft delete) and signs the person out everywhere. */
    public function removeAccount(Offboarding $o, ?int $actorId = null, string $action = 'removed'): Offboarding
    {
        DB::transaction(function () use ($o, $actorId, $action) {
            $user = User::find($o->user_id);
            if ($user) {
                try {
                    $user->tokens()->delete();
                } catch (\Throwable $e) {
                    // no tokens table / relation: the soft delete below already blocks sign-in
                }
                $user->delete();
            }
            $o->status = Offboarding::COMPLETED;
            $o->completed_at = now();
            $o->save();
            $this->log($o, $action, $action === 'auto_removed' ? 'Account removed automatically on its scheduled day' : 'Account removed', $actorId);
        });

        Offboarding::flush();

        return $o->fresh();
    }

    /** Cancels a scheduled offboarding: nothing changes for the person and the old end date is put back. */
    public function cancel(Offboarding $o, ?int $actorId = null): Offboarding
    {
        DB::transaction(function () use ($o, $actorId) {
            $o->status = Offboarding::CANCELLED;
            $o->cancelled_at = now();
            $o->cancelled_by = $actorId ?? Auth::id();
            $o->save();
            $this->setHireEnd($o->user_id, $o->previous_hire_end_date ? $o->previous_hire_end_date->toDateString() : null);
            $this->log($o, 'cancelled', 'Offboarding cancelled; nothing changes for the employee', $actorId);
        });

        Offboarding::flush();

        return $o->fresh();
    }

    /** Brings back a removed account. */
    public function reinstate(Offboarding $o, ?int $actorId = null): Offboarding
    {
        DB::transaction(function () use ($o, $actorId) {
            $user = User::withTrashed()->find($o->user_id);
            if ($user && $user->trashed()) {
                $user->restore();
            }
            $o->status = Offboarding::REINSTATED;
            $o->cancelled_at = now();
            $o->cancelled_by = $actorId ?? Auth::id();
            $o->save();
            $this->setHireEnd($o->user_id, $o->previous_hire_end_date ? $o->previous_hire_end_date->toDateString() : null);
            $this->log($o, 'reinstated', 'Account restored and end date cleared', $actorId);
        });

        Offboarding::flush();

        return $o->fresh();
    }

    /** Removes every account whose day has come. Returns how many. Safe to run any number of times. */
    public function processDue(): int
    {
        if (!Offboarding::ready()) {
            return 0;
        }

        $n = 0;
        $due = Offboarding::where('status', Offboarding::SCHEDULED)->whereDate('removal_date', '<=', Carbon::today())->get();
        foreach ($due as $o) {
            try {
                $this->removeAccount($o, null, 'auto_removed');
                $n++;
            } catch (\Throwable $e) {
                \Log::warning('Offboarding auto-removal failed for user ' . $o->user_id . ': ' . $e->getMessage());
            }
        }

        return $n;
    }

    /**
     * What this departure does to payroll, for the screen to show BEFORE anything is saved:
     * days paid, estimated pay, unpaid-leave deduction, and any warning about a payroll that already exists.
     */
    public function payrollImpact(User $user, Carbon $lastDay, ?string $reason = null, $noticeDate = null): array
    {
        $month = $lastDay->copy()->startOfMonth();
        $profile = SalaryProfile::where('user_id', $user->id)->first();

        $out = [
            'month' => $month->toDateString(),
            'month_label' => $month->format('F Y'),
            'has_salary' => (bool) ($profile && (float) $profile->monthly_salary > 0),
            'warnings' => [],
        ];

        $run = PayrollRun::whereDate('period_month', $month)->where('status', '!=', PayrollRun::CANCELLED)->first();
        if ($run) {
            $out['run'] = ['id' => $run->id, 'status' => $run->status];
            $out['warnings'][] = in_array($run->status, [PayrollRun::PAID, PayrollRun::CLOSED], true)
                ? $month->format('F Y') . ' payroll is already ' . strtolower($run->status) . ', so it will not change. Any difference has to be adjusted by hand.'
                : $month->format('F Y') . " payroll already exists. Open it and press \"Refresh\" so this person's pay is corrected.";
        }

        if (!$out['has_salary']) {
            $out['warnings'][] = 'No salary is set for this person, so there is nothing to pay.';

            return $out;
        }

        // Notice period (resignation / termination): leave taken inside it is unpaid, so it is part of the preview.
        $notice = null;
        if (NoticePolicy::hasNotice($reason)) {
            $from = $noticeDate ? Carbon::parse($noticeDate)->startOfDay() : Carbon::today();
            $notice = $from->lte($lastDay->copy()->startOfDay()) ? [$from, $lastDay->copy()->startOfDay()] : null;
        }

        $plan = app(PayrollGenerator::class)->plan($profile, $user, $month, true, $lastDay->copy()->startOfDay(), $notice);
        $out['currency'] = $profile->currency ?: 'PKR';
        $out['monthly_salary'] = (float) $profile->monthly_salary;
        $out['skip'] = $plan['skip'];
        $out['days_in_month'] = $plan['days_in_month'];
        $out['payable_days'] = $plan['skip'] ? 0 : $plan['payable_days'];
        $out['basic'] = $plan['skip'] ? 0.0 : (float) $plan['basic'];
        $out['unpaid'] = $plan['unpaid'];
        $out['estimated_net'] = $plan['skip'] ? 0.0 : max(0, round((float) $plan['basic'] - (float) ($plan['unpaid']['amount'] ?? 0), 2));
        $out['notes'] = $plan['notes'];

        return $out;
    }
}
