<?php

namespace App\Services;

use App\Models\PayrollPayee;
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
        $this->skipped = [];

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
        $users = $this->staffFor($profiles->pluck('user_id')->all(), $month);
        // Service providers are paid through the same profiles; they have no user account, so they come from their own table.
        $payees = $this->activePayees($profiles->pluck('user_id')->all());

        $standing = PayrollSchema::table('recurring_payroll_items')
            ? RecurringPayrollItem::appliesTo($month)->get()->groupBy('user_id')
            : collect();

        $run = DB::transaction(function () use ($month, $notes, $profiles, $users, $payees, $standing, $actorId, $prorate, $source) {
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
                $user = $users->get($profile->user_id) ?? $payees->get($profile->user_id);
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

        $left = collect($this->skipped)->map(fn ($x) => $x['name'] . ' (' . $x['reason'] . ')')->take(6)->implode('; ');
        $message = 'Payroll generated for ' . $month->format('F Y') . '.' . ($left ? ' Left out: ' . $left . '.' : '');

        return ['status' => 'ok', 'run' => $run->fresh(), 'message' => $message, 'skipped' => $this->skipped];
    }

    /**
     * Works out one person's pay for one month WITHOUT writing anything: whether they are in this payroll at all, how many
     * days they are paid for, any unpaid-leave deduction, and the plain-language notes that explain each of those.
     *
     * Rules (all explained to the reader in `notes`):
     *  - joined in a later month                      -> not in this payroll
     *  - left before this month began                 -> not in this payroll
     *  - joined AND left within this same month       -> no salary at all
     *  - joined / left mid-month                      -> paid for the days between (prorated)
     *  - approved UNPAID leave inside the paid days   -> a deduction of (monthly salary / days in the month) per day (unpaid leave is counted in calendar days, as the leave policy says)
     */
    public function plan(SalaryProfile $profile, object $user, Carbon $month, bool $prorate, ?Carbon $leftOverride = null, ?array $noticeWindow = null): array
    {
        $dim      = $month->daysInMonth;
        $monthEnd = $month->copy()->endOfMonth()->startOfDay();
        $joined   = $user->joining_date ? Carbon::parse($user->joining_date)->startOfDay() : null;
        $left     = $leftOverride ?: $this->lastDay($user);
        $cur      = $profile->currency ?: 'PKR';
        $full     = (float) $profile->monthly_salary;

        $plan = [
            'skip' => null, 'days_in_month' => $dim, 'payable_days' => $dim,
            'first' => $month->copy(), 'last' => $monthEnd, 'basic' => $full,
            'unpaid' => null, 'notes' => [], 'joined' => $joined, 'left' => $left,
        ];

        if ($joined && $joined->gt($monthEnd)) {
            $plan['skip'] = 'Starts in a later month';

            return $plan;
        }
        if ($left && $left->lt($month)) {
            $plan['skip'] = 'Left before ' . $month->format('F Y') . ' began';

            return $plan;
        }
        if ($joined && $left && $joined->gte($month) && $left->lte($monthEnd)) {
            $plan['skip'] = 'Joined on ' . $joined->format('j M') . ' and left on ' . $left->format('j M') . ' in the same month, so no salary is due';

            return $plan;
        }

        $first = ($prorate && $joined && $joined->gt($month)) ? $joined->copy() : $month->copy();
        $last  = ($prorate && $left && $left->lt($monthEnd)) ? $left->copy() : $monthEnd->copy();
        $days  = $prorate ? max(1, min($dim, (int) $first->diffInDays($last) + 1)) : $dim;
        $basic = $days < $dim ? round($full * $days / $dim, 2) : $full;

        $plan['first'] = $first;
        $plan['last'] = $last;
        $plan['payable_days'] = $days;
        $plan['basic'] = $basic;

        if ($days < $dim) {
            $why = [];
            if ($first->gt($month)) {
                $why[] = 'joined on ' . $first->format('j M Y');
            }
            if ($last->lt($monthEnd)) {
                $o = $left ? \App\Models\Offboarding::forUser((int) ($user->id ?? 0)) : null;
                $why[] = 'last working day is ' . $last->format('j M Y') . ($o ? ' (' . strtolower($o->reason_type) . ')' : '');
            }
            $plan['notes'][] = ['kind' => 'days', 'text' => 'Paid for ' . $days . ' of ' . $dim . ' days (' . implode(', ', $why) . '): ' . $cur . ' ' . number_format($full, 2) . ' × ' . $days . '/' . $dim . ' = ' . number_format($basic, 2) . '.'];
        }

        // Approved unpaid leave inside the days they are actually paid for. Real accounts only (providers have no leave).
        if ((int) ($user->id ?? 0) > 0 && (int) $user->id < \App\Models\PayrollPayee::BASE) {
            // Leave taken during a notice period (resignation or termination) is never paid.
            $notice = $noticeWindow ?? \App\Models\Offboarding::noticeWindow((int) $user->id);
            $leave = $this->unpaidLeave((int) $user->id, $first, $last, $notice);
            if ($leave['days'] > 0) {
                $rate = $dim > 0 ? $full / $dim : 0;
                $amount = min($basic, round($rate * $leave['days'], 2));
                $label = 'Unpaid leave: ' . $leave['days'] . ' day' . ($leave['days'] === 1 ? '' : 's') . ' (' . $leave['text'] . ')';
                $plan['unpaid'] = ['days' => $leave['days'], 'amount' => $amount, 'label' => $label];
                $plan['notes'][] = ['kind' => 'deduction', 'text' => $label . ' deducted at ' . $cur . ' ' . number_format($rate, 2) . ' a day (monthly salary ÷ ' . $dim . ' days in ' . $month->format('F')
                    . ', weekends inside the leave count) = ' . $cur . ' ' . number_format($amount, 2) . '.'];
            }
        }

        return $plan;
    }

    /**
     * Unpaid days between two dates, in CALENDAR days (the leave policy counts these leaves that way), each day once even if
     * requests overlap. Three things are unpaid under the policy:
     *   - approved leave of type "unpaid";
     *   - marriage leave beyond the 15 paid days (a lifetime allowance, counted across all marriage requests);
     *   - maternity leave beyond the first 60 paid days of a request (the third month).
     */
    public function unpaidLeave(int $userId, Carbon $from, Carbon $to, ?array $notice = null): array
    {
        $out = ['days' => 0, 'text' => ''];
        if (!PayrollSchema::table('leave_requests')) {
            return $out;
        }

        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        try {
            $segments = []; // [start, end, suffix]

            $plain = \App\Models\LeaveRequest::where('user_id', $userId)
                ->where('status', 'Approved')
                ->whereRaw('LOWER(TRIM(leave_type)) = ?', ['unpaid'])
                ->whereDate('start_date', '<=', $to)
                ->whereDate('end_date', '>=', $from)
                ->orderBy('start_date')
                ->get(['start_date', 'end_date']);
            foreach ($plain as $r) {
                $segments[] = [Carbon::parse($r->start_date)->startOfDay(), Carbon::parse($r->end_date)->startOfDay(), ''];
            }

            // Notice period: ANY approved leave inside it (casual, sick, annual, ...) is unpaid.
            if ($notice) {
                $inNotice = \App\Models\LeaveRequest::where('user_id', $userId)
                    ->where('status', 'Approved')
                    ->whereDate('start_date', '<=', $notice[1])
                    ->whereDate('end_date', '>=', $notice[0])
                    ->orderBy('start_date')
                    ->get(['start_date', 'end_date']);
                foreach ($inNotice as $r) {
                    $a = Carbon::parse($r->start_date)->startOfDay()->max($notice[0]->copy()->startOfDay());
                    $b = Carbon::parse($r->end_date)->startOfDay()->min($notice[1]->copy()->startOfDay());
                    if ($a->lte($b)) {
                        $segments[] = [$a, $b, ' leave during the notice period'];
                    }
                }
            }

            // Marriage: 15 paid days for the whole employment; whatever goes past that is unpaid.
            $paidLeft = \App\Services\LeavePolicy::MARRIAGE_DAYS;
            $marriage = \App\Models\LeaveRequest::where('user_id', $userId)
                ->where('status', 'Approved')
                ->whereRaw('LOWER(TRIM(leave_type)) = ?', ['marriage'])
                ->orderBy('start_date')
                ->get(['start_date', 'end_date']);
            foreach ($marriage as $r) {
                $a = Carbon::parse($r->start_date)->startOfDay();
                $b = Carbon::parse($r->end_date)->startOfDay();
                $n = (int) $a->diffInDays($b) + 1;
                if ($n > $paidLeft) {
                    $segments[] = [$a->copy()->addDays($paidLeft), $b, ' marriage leave beyond the 15 paid days'];
                }
                $paidLeft = max(0, $paidLeft - $n);
            }

            // Maternity: the first 60 days of a request are paid; the third month is unpaid.
            $maternity = \App\Models\LeaveRequest::where('user_id', $userId)
                ->where('status', 'Approved')
                ->whereRaw('LOWER(TRIM(leave_type)) = ?', ['maternity'])
                ->whereDate('end_date', '>=', $from)
                ->orderBy('start_date')
                ->get(['start_date', 'end_date']);
            foreach ($maternity as $r) {
                $a = Carbon::parse($r->start_date)->startOfDay()->addDays(\App\Services\LeavePolicy::MATERNITY_PAID_DAYS);
                $b = Carbon::parse($r->end_date)->startOfDay();
                if ($a->lte($b)) {
                    $segments[] = [$a, $b, ' maternity leave beyond the 60 paid days'];
                }
            }
        } catch (\Throwable $e) {
            return $out;
        }

        $days = [];
        $parts = [];
        foreach ($segments as [$start, $end, $suffix]) {
            $a = $start->max($from);
            $b = $end->min($to);
            if ($a->gt($b)) {
                continue;
            }
            $count = 0;
            for ($d = $a->copy(); $d->lte($b); $d->addDay()) {
                if (!isset($days[$d->toDateString()])) {
                    $days[$d->toDateString()] = true;
                    $count++;
                }
            }
            if ($count > 0) {
                $range = $a->isSameDay($b) ? $a->format('j M') : ($a->format('M') === $b->format('M') ? $a->format('j') . '–' . $b->format('j M') : $a->format('j M') . ' – ' . $b->format('j M'));
                $parts[] = $range . ($suffix ? ',' . $suffix : '');
            }
        }

        $out['days'] = count($days);
        $out['text'] = implode('; ', $parts);

        return $out;
    }

    private function unpaidItem(Payslip $slip, array $unpaid, ?int $actorId): void
    {
        $slip->items()->create(PayrollSchema::only('payslip_items', [
            'kind'       => PayslipItem::DEDUCTION,
            'category'   => 'Absence',
            'label'      => $unpaid['label'],
            'amount'     => (float) $unpaid['amount'],
            'notes'      => 'Auto: unpaid leave',
            'created_by' => $actorId,
        ]));
    }

    /** People left out of the payroll just generated (with the reason), for the message shown to the user. */
    public array $skipped = [];

    /**
     * One payslip (with its standing items) for one employee, or null when they are not in this month's payroll
     * (see plan() for the rules).
     */
    public function createSlip(PayrollRun $run, SalaryProfile $profile, object $user, Carbon $month, int $seq, ?int $actorId, bool $prorate, $standingItems = null): ?Payslip
    {
        $plan = $this->plan($profile, $user, $month, $prorate);
        if ($plan['skip']) {
            if (str_starts_with($plan['skip'], 'Joined')) {
                $this->skipped[] = ['name' => $user->name, 'reason' => $plan['skip']];
            }

            return null;
        }

        $dim    = $plan['days_in_month'];
        $days   = $plan['payable_days'];
        $basic  = $plan['basic'];

        $slip = Payslip::create(PayrollSchema::only('payslips', [
            'payroll_run_id' => $run->id,
            'user_id'        => $user->id,
            'period_month'   => $month,
            'payslip_no'     => $this->nextPayslipNo($month, $seq),

            'basic_salary'   => $basic,
            'gross_amount'   => $basic,
            'net_amount'     => $basic,
            'currency'       => $profile->currency ?: 'PKR',
            'payout_route'   => in_array($profile->payout_route ?? null, ['payoneer', 'local'], true) ? $profile->payout_route : 'payoneer',
            'status'         => 'Pending',

            'payment_method'    => $profile->payment_method,
            'payment_reference' => null, // the TRANSFER reference, filled when paid (the profile's reference is the account)

            // Snapshot — a reprinted payslip must show what was true when it was issued.
            'employee_name'  => $user->name,
            'employee_email' => $user->email,
            'designation'    => $user->employee_type,
            'department'     => $user->employee_team,
            'joining_date'   => $user->joining_date,

            'is_prorated'    => $days < $dim,
            'days_payable'   => $days,
            'days_in_month'  => $dim,

            'pay_notes'      => $plan['notes'] ?: null,

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

        if ($plan['unpaid']) {
            $this->unpaidItem($slip, $plan['unpaid'], $actorId);
        }

        return $slip->recalculate();
    }

    /**
     * Employees for a payroll month. People whose account was deleted DURING the month (or later) are still included,
     * so they are paid up to their last day; people deleted before the month began are not.
     */
    public function staffFor(array $ids, Carbon $month)
    {
        $start = $month->copy()->startOfMonth();

        return User::withTrashed()
            ->whereIn('id', $ids)
            ->where('user_role', 3)
            ->where(function ($q) use ($start) {
                $q->whereNull('deleted_at')->orWhere('deleted_at', '>=', $start);
            })
            ->get()
            ->keyBy('id');
    }

    /**
     * The last day someone is paid for, or null for everyone still here.
     * An offboarding record wins; otherwise a deleted account falls back to its hire end date, then the deletion date.
     */
    public function lastDay(object $user): ?Carbon
    {
        $id = (int) ($user->id ?? 0);
        if ($id > 0 && $id < PayrollPayee::BASE) {
            $o = \App\Models\Offboarding::forUser($id);
            if ($o && $o->last_working_day) {
                return Carbon::parse($o->last_working_day)->startOfDay();
            }
        }

        $deleted = $user->deleted_at ?? null;
        if (!$deleted) {
            return null;
        }
        $end = $user->hire_end_date ?? null;

        try {
            return Carbon::parse($end ?: $deleted)->startOfDay();
        } catch (\Throwable $e) {
            return Carbon::parse($deleted)->startOfDay();
        }
    }

    /**
     * Brings a still-unpaid payslip up to date with what is now known: someone who has since left (or never should
     * have been paid), and approved unpaid leave. Also rewrites the "why this amount" notes.
     * Returns true when anything changed. Payslips whose days were set by hand keep their days.
     */
    public function refreshSlip(Payslip $slip, object $user, ?SalaryProfile $profile, Carbon $month, ?int $actorId = null): bool
    {
        if (!$profile || !in_array($slip->status, ['Pending', 'Approved'], true)) {
            return false;
        }

        $plan = $this->plan($profile, $user, $month, true);
        $changed = false;

        if ($plan['skip']) {
            if (str_starts_with($plan['skip'], 'Starts in')) {
                return false;
            }
            $slip->status = 'Skipped';
            if (PayrollSchema::has('payslips', 'status_reason')) {
                $slip->status_reason = $plan['skip'];
            }
            $slip->save();

            return true;
        }

        $dim = $plan['days_in_month'];
        $manualDays = false;
        if (PayrollSchema::has('payslips', 'is_prorated')) {
            $full = (float) $profile->monthly_salary;
            $days = (int) $slip->days_payable ?: $dim;
            // A slip whose basic still equals salary x days / month-days was set by the system, so it follows the dates.
            // One that was edited by hand keeps its numbers (and says so in the notes).
            $untouched = !$slip->is_prorated || abs((float) $slip->basic_salary - round($full * $days / $dim, 2)) < 0.01;
            if ($untouched && ($days !== (int) $plan['payable_days'] || (bool) $slip->is_prorated !== ($plan['payable_days'] < $dim))) {
                $slip->basic_salary = $slip->is_prorated
                    ? $plan['basic']
                    : ($plan['payable_days'] < $dim ? round((float) $slip->basic_salary * $plan['payable_days'] / $dim, 2) : (float) $slip->basic_salary);
                $slip->is_prorated = $plan['payable_days'] < $dim;
                $slip->days_payable = $plan['payable_days'];
                $slip->days_in_month = $dim;
                $slip->save();
                $changed = true;
            } elseif (!$untouched && $days !== (int) $plan['payable_days']) {
                $manualDays = true;
            }
        }

        // Unpaid leave: rebuild the automatic line so approving / removing leave later is picked up.
        $before = (float) $slip->items()->where('notes', 'Auto: unpaid leave')->sum('amount');
        $slip->items()->where('notes', 'Auto: unpaid leave')->delete();
        if ($plan['unpaid']) {
            $unpaid = $plan['unpaid'];
            $unpaid['amount'] = min((float) $slip->basic_salary, (float) $unpaid['amount']);
            $this->unpaidItem($slip, $unpaid, $actorId);
        }
        $after = (float) $slip->items()->where('notes', 'Auto: unpaid leave')->sum('amount');
        if (abs($before - $after) > 0.001) {
            $changed = true;
        }
        $slip->recalculate();

        if (PayrollSchema::has('payslips', 'pay_notes')) {
            $notes = $manualDays
                ? array_values(array_filter($plan['notes'], fn ($n) => $n['kind'] !== 'days'))
                : $plan['notes'];
            if ($manualDays) {
                array_unshift($notes, ['kind' => 'days', 'text' => 'Paid for ' . (int) $slip->days_payable . ' of ' . $dim . ' days (set by hand).']);
            }
            $new = $notes ?: null;
            if ($new !== $slip->pay_notes) {
                $slip->pay_notes = $new;
                $slip->save();
                $changed = $changed || $new !== null;
            }
        }

        return $changed;
    }

    /**
     * Active service providers that have one of these (virtual) user ids, as the subject objects createSlip() reads,
     * keyed by virtual id. Empty until the providers table exists.
     */
    public function activePayees(array $virtualIds)
    {
        if (!PayrollSchema::table('payroll_payees')) {
            return collect();
        }

        return PayrollPayee::keyedByVirtualId($virtualIds)
            ->filter(fn ($p) => $p->status === 'Active' && !$p->deleted_at)
            ->map(fn ($p) => $p->asSubject());
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
