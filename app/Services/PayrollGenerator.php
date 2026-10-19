<?php

namespace App\Services;

use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\RecurringPayrollItem;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Support\PayrollSchema;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds a month's payroll. Shared by the admin button and the monthly
 * scheduler, so both produce exactly the same thing.
 *
 * Per employee with an Active salary:
 *  - basic salary, PRORATED by calendar days when they joined after the 1st
 *    (joining on the 10th of a 30-day month → 21/30 of the salary);
 *  - every standing item (allowance / instalment / withholding) that applies to
 *    the month, added as a normal payslip line;
 *  - a snapshot of name, title, team and joining date, so a reprint always shows
 *    what was true then.
 * Someone who has not joined yet by month end gets no payslip.
 */
class PayrollGenerator
{
    /**
     * @return array{status: string, run: ?PayrollRun, message: string}
     *         status: ok | exists | empty
     */
    public function generate(Carbon $month, ?int $actorId = null, ?string $notes = null, bool $prorate = true, string $source = 'manual'): array
    {
        $month = $month->copy()->startOfMonth();

        $existing = PayrollRun::whereDate('period_month', $month)
            ->where('status', '!=', PayrollRun::CANCELLED)
            ->first();

        if ($existing) {
            return ['status' => 'exists', 'run' => $existing, 'message' => 'Payroll for ' . $month->format('F Y') . ' already exists.'];
        }

        $profiles = SalaryProfile::payable()->get();
        if ($profiles->isEmpty()) {
            return ['status' => 'empty', 'run' => null, 'message' => 'No employees have an active salary set, so there is nothing to generate.'];
        }

        // Only real, current employees — a soft-deleted or non-employee account
        // with a stale salary profile must never be swept into payroll.
        $users = User::whereIn('id', $profiles->pluck('user_id'))->where('user_role', 3)->get()->keyBy('id');

        $standing = PayrollSchema::table('recurring_payroll_items')
            ? RecurringPayrollItem::appliesTo($month)->get()->groupBy('user_id')
            : collect();

        $run = DB::transaction(function () use ($month, $notes, $profiles, $users, $standing, $actorId, $prorate, $source) {
            $run = PayrollRun::create(PayrollSchema::only('payroll_runs', [
                'period_month' => $month,
                'title'        => $month->format('F Y'),
                'status'       => PayrollRun::DRAFT,
                'notes'        => $notes,
                'created_by'   => $actorId,
                'source'       => $source,
            ]));

            $seq = 0;
            foreach ($profiles as $profile) {
                $user = $users->get($profile->user_id);
                if (!$user) {
                    continue;
                }

                $slip = $this->createSlip($run, $profile, $user, $month, $seq + 1, $actorId, $prorate, $standing->get($user->id));
                if ($slip) {
                    $seq++;
                }
            }

            return $run->recalculate();
        });

        PayrollAudit::log(
            $source === 'auto' ? 'run.generated_auto' : 'run.generated',
            $run->id,
            null,
            null,
            null,
            $month->format('F Y'),
            (float) $run->total_net,
            ['employees' => $run->employee_count],
            $actorId
        );

        return ['status' => 'ok', 'run' => $run->fresh(), 'message' => 'Payroll generated for ' . $month->format('F Y') . '.'];
    }

    /**
     * One payslip (with its standing items) for one employee, or null when they
     * had not joined by the end of the month.
     */
    public function createSlip(PayrollRun $run, SalaryProfile $profile, User $user, Carbon $month, int $seq, ?int $actorId, bool $prorate, $standingItems = null): ?Payslip
    {
        $daysInMonth = $month->daysInMonth;
        $payableDays = $daysInMonth;

        $joined = $user->joining_date ? Carbon::parse($user->joining_date)->startOfDay() : null;
        if ($joined && $joined->gt($month->copy()->endOfMonth())) {
            return null; // starts in a later month
        }
        if ($prorate && $joined && $joined->gt($month)) {
            $payableDays = $daysInMonth - $joined->day + 1;
        }

        $full  = (float) $profile->monthly_salary;
        $basic = $payableDays < $daysInMonth ? round($full * $payableDays / $daysInMonth, 2) : $full;

        $slip = Payslip::create(PayrollSchema::only('payslips', [
            'payroll_run_id' => $run->id,
            'user_id'        => $user->id,
            'period_month'   => $month,
            'payslip_no'     => $this->nextPayslipNo($month, $seq),

            'basic_salary'   => $basic,
            'gross_amount'   => $basic,
            'net_amount'     => $basic,
            'currency'       => $profile->currency ?: 'PKR',
            'status'         => 'Pending',

            'payment_method'    => $profile->payment_method,
            'payment_reference' => null, // the TRANSFER reference, filled when paid (the profile's reference is the account)

            // Snapshot — a reprinted payslip must show what was true when it was issued.
            'employee_name'  => $user->name,
            'employee_email' => $user->email,
            'designation'    => $user->employee_type,
            'department'     => $user->employee_team,
            'joining_date'   => $user->joining_date,

            'is_prorated'    => $payableDays < $daysInMonth,
            'days_payable'   => $payableDays,
            'days_in_month'  => $daysInMonth,

            'created_by'     => $actorId,
        ]));

        foreach (($standingItems ?? collect()) as $item) {
            $slip->items()->create(PayrollSchema::only('payslip_items', [
                'kind'              => $item->kind,
                'category'          => $item->category,
                'label'             => $item->label,
                'amount'            => (float) $item->amount,
                'notes'             => 'Standing item',
                'recurring_item_id' => $item->id,
                'created_by'        => $actorId,
            ]));
        }

        return $slip->recalculate();
    }

    /** ALLC-2026-10-0001 style; skips numbers already taken (items added later by "sync"). */
    private function nextPayslipNo(Carbon $month, int $seq): string
    {
        do {
            $no = sprintf('ALLC-%s-%04d', $month->format('Y-m'), $seq);
            $seq++;
        } while (Payslip::where('payslip_no', $no)->exists());

        return $no;
    }
}
