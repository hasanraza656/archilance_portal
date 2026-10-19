<?php

namespace App\Http\Controllers\API\admin;

use App\Http\Controllers\Controller;
use App\Models\PayrollAuditLog;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Services\PayrollAudit;
use App\Services\PayrollGenerator;
use App\Services\PayrollWorkflow;
use App\Support\PayrollSchema;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Monthly payroll: generate a run, adjust it, get it approved, pay it, close the month.
 *
 * ── THE LIFECYCLE ───────────────────────────────────────────────────────────
 *   Draft / Pending Approval  → payslips editable, nothing can be marked paid
 *   Approved                  → payments may be recorded; unpaid slips can still be adjusted
 *   Paid                      → set automatically once every payable slip settles
 *   Closed                    → the month is locked (an admin can reopen it, audit-logged)
 *   Cancelled                 → terminal; generate a fresh run instead
 *
 * Every mutating action re-reads the run and asks the model (isEditable / isPayable /
 * canAdjust / isClosed), so the rules cannot be bypassed by calling the API directly.
 *
 * ── PAYSLIP STATUS ──────────────────────────────────────────────────────────
 *   Pending → Approved → Paid, plus the side states On Hold and Skipped. A single
 *   `transition()` applies every change, so single actions, bulk actions and the
 *   one-click "approve & pay all" all obey the same rules and write the same audit trail.
 *
 * Payments are manual: the finance team makes the transfer and records it here
 * (method + reference). A payment integration would fill the same two columns.
 */
class PayrollController extends Controller
{
    /** Payslip statuses that still wait for money. */
    private const AWAITING = ['Pending', 'Approved'];

    /* ------------------------------- runs ------------------------------- */

    public function index(Request $request)
    {
        $runs = PayrollRun::with(['creator:id,name', 'approver:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('period_month')
            ->orderByDesc('id')
            ->limit(60)
            ->get();

        PayrollRun::attachMoney($runs);

        return response()->json([
            'data'     => $runs,
            'advanced' => PayrollSchema::advanced(),
        ]);
    }

    public function show($id)
    {
        $run = PayrollRun::with(['creator:id,name', 'approver:id,name'])->findOrFail($id);
        PayrollRun::attachMoney([$run]);

        $payslips = Payslip::with('items')
            ->where('payroll_run_id', $run->id)
            ->orderBy('employee_name')
            ->get();

        // Account / wallet the person is paid into (from their salary profile), for the payment sheet.
        $profiles = SalaryProfile::whereIn('user_id', $payslips->pluck('user_id'))->get()->keyBy('user_id');
        $payslips->each(function ($slip) use ($profiles) {
            $slip->setAttribute('payment_account', $profiles->get($slip->user_id)->payment_reference ?? null);
        });

        return response()->json([
            'data'       => $run,
            'payslips'   => $payslips,
            'editable'   => $run->isEditable(),
            'payable'    => $run->isPayable(),
            'adjustable' => $run->canAdjust(),
            'closed'     => $run->isClosed(),
            'advanced'   => PayrollSchema::advanced(),
        ]);
    }

    /**
     * Builds a run for a month from every Active salary profile (prorating new joiners and
     * adding each person's standing items). Refused when the month already has a live run —
     * cancel it first; only Cancelled runs are ignored, which is why period_month isn't unique.
     */
    public function generate(Request $request, PayrollGenerator $generator)
    {
        $data = $request->validate([
            'month'   => 'required|date',
            'notes'   => 'nullable|string|max:2000',
            'prorate' => 'nullable|boolean',
        ]);

        $result = $generator->generate(
            Carbon::parse($data['month']),
            Auth::id(),
            $data['notes'] ?? null,
            $request->boolean('prorate', true)
        );

        if ($result['status'] === 'exists') {
            return response()->json(['message' => $result['message'], 'data' => $result['run']], 409);
        }
        if ($result['status'] === 'empty') {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json(['message' => $result['message'], 'data' => $result['run']]);
    }

    /** Draft → Pending Approval. */
    public function submit($id)
    {
        $run = PayrollRun::findOrFail($id);

        if ($run->status !== PayrollRun::DRAFT) {
            return response()->json(['message' => 'Only a draft payroll can be sent for approval.'], 422);
        }

        $run->update(['status' => PayrollRun::PENDING]);
        PayrollAudit::log('run.submitted', $run->id, null, null, PayrollRun::DRAFT, PayrollRun::PENDING);

        return response()->json(['message' => 'Sent for approval.', 'data' => $run->fresh()]);
    }

    /** Draft / Pending Approval → Approved. Approves every pending payslip with it. */
    public function approve($id)
    {
        if ($deny = $this->requireAdmin('approve payrolls')) {
            return $deny;
        }

        $run = PayrollRun::findOrFail($id);

        if (!in_array($run->status, [PayrollRun::DRAFT, PayrollRun::PENDING], true)) {
            return response()->json(['message' => 'This payroll has already been approved or closed.'], 422);
        }

        $this->approveRun($run);

        return response()->json(['message' => 'Payroll approved.', 'data' => $run->fresh()]);
    }

    /**
     * One click: approve the run (if it is not already) and mark every payable payslip paid.
     * Held and skipped payslips are left alone, and the response says how many.
     */
    public function approveAndPay(Request $request, $id)
    {
        if ($deny = $this->requireAdmin('approve and pay a payroll')) {
            return $deny;
        }

        $run = PayrollRun::findOrFail($id);

        if (in_array($run->status, [PayrollRun::CLOSED, PayrollRun::CANCELLED], true)) {
            return response()->json(['message' => 'This payroll is ' . strtolower($run->status) . ' and cannot be paid.'], 422);
        }

        $opts = $this->paymentOptions($request);

        $outcome = DB::transaction(function () use ($run, $opts) {
            if (in_array($run->status, [PayrollRun::DRAFT, PayrollRun::PENDING], true)) {
                $this->approveRun($run);
                $run->refresh();
            }

            return $this->applyToSlips($run, $run->payslips()->whereIn('status', self::AWAITING)->get(), 'pay', $opts);
        });

        PayrollAudit::log('run.approved_and_paid', $run->id, null, null, null, null, $outcome['amount'], ['paid' => $outcome['done']]);

        return response()->json([
            'message' => $this->summaryMessage('marked as paid', $outcome),
            'data'    => $run->fresh(),
            'done'    => $outcome['done'],
            'ignored' => $outcome['ignored'],
        ]);
    }

    /** Sends an approved run back for edits (only while nobody has been paid). */
    public function reopen($id)
    {
        if ($deny = $this->requireAdmin('reopen an approved payroll')) {
            return $deny;
        }

        $run = PayrollRun::findOrFail($id);

        if ($run->isClosed() || $run->status === PayrollRun::CANCELLED) {
            return response()->json(['message' => 'A ' . strtolower($run->status) . ' payroll cannot be reopened this way.'], 422);
        }

        if ($run->payslips()->where('status', 'Paid')->exists()) {
            return response()->json([
                'message' => 'Some payslips in this payroll are already paid, so it can no longer be reopened.',
            ], 422);
        }

        DB::transaction(function () use ($run) {
            $run->update([
                'status'      => PayrollRun::DRAFT,
                'approved_by' => null,
                'approved_at' => null,
            ]);

            // The approval is withdrawn, so the per-payslip approvals go with it.
            $run->payslips()->where('status', 'Approved')->update(
                PayrollSchema::only('payslips', ['status' => 'Pending', 'approved_by' => null, 'approved_at' => null])
            );
            $run->recalculate();
        });

        PayrollAudit::log('run.reopened', $run->id, null, null, PayrollRun::APPROVED, PayrollRun::DRAFT);

        return response()->json(['message' => 'Payroll reopened for editing.', 'data' => $run->fresh()]);
    }

    public function cancel($id)
    {
        if ($deny = $this->requireAdmin('cancel a payroll')) {
            return $deny;
        }

        $run = PayrollRun::findOrFail($id);

        if ($run->isClosed()) {
            return response()->json(['message' => 'A closed month cannot be cancelled. Reopen the period first.'], 422);
        }

        if ($run->payslips()->where('status', 'Paid')->exists()) {
            return response()->json([
                'message' => 'Some payslips in this payroll are already paid, so it cannot be cancelled.',
            ], 422);
        }

        $from = $run->status;
        $run->update(['status' => PayrollRun::CANCELLED]);
        PayrollAudit::log('run.cancelled', $run->id, null, null, $from, PayrollRun::CANCELLED);

        return response()->json(['message' => 'Payroll cancelled.', 'data' => $run->fresh()]);
    }

    /** Paid → Closed: locks the month. Held / skipped payslips are left as they are. */
    public function close($id)
    {
        if ($deny = $this->requireAdmin('close a month')) {
            return $deny;
        }

        $run = PayrollRun::findOrFail($id);

        if ($run->status !== PayrollRun::PAID) {
            return response()->json(['message' => 'A month can only be closed once every payable payslip is paid.'], 422);
        }

        $run->status = PayrollRun::CLOSED;
        foreach (PayrollSchema::only('payroll_runs', ['closed_at' => now(), 'closed_by' => Auth::id()]) as $k => $v) {
            $run->{$k} = $v;
        }
        $run->save();

        PayrollAudit::log('run.closed', $run->id, null, null, PayrollRun::PAID, PayrollRun::CLOSED, (float) $run->total_net);

        return response()->json(['message' => 'The month is closed.', 'data' => $run->fresh()]);
    }

    /** Closed → Paid: unlocks a closed month. A reason is required and goes into the audit trail. */
    public function reopenPeriod(Request $request, $id)
    {
        if ($deny = $this->requireAdmin('reopen a closed month')) {
            return $deny;
        }

        $data = $request->validate(['reason' => 'required|string|min:3|max:500']);

        $run = PayrollRun::findOrFail($id);

        if (!$run->isClosed()) {
            return response()->json(['message' => 'This month is not closed.'], 422);
        }

        $run->status = PayrollRun::PAID;
        foreach (PayrollSchema::only('payroll_runs', ['closed_at' => null, 'closed_by' => null]) as $k => $v) {
            $run->{$k} = $v;
        }
        $run->save();
        $run->recalculate();

        PayrollAudit::log('run.period_reopened', $run->id, null, null, PayrollRun::CLOSED, $run->status, null, $data['reason']);

        return response()->json(['message' => 'The month is open again.', 'data' => $run->fresh()]);
    }

    /**
     * Pulls changes made since generation into a run that is still being prepared:
     * adds people whose salary was set (or who joined) afterwards, and optionally
     * refreshes basic pay from the current salary of unpaid, non-prorated payslips.
     */
    public function sync(Request $request, $id, PayrollGenerator $generator)
    {
        $run = PayrollRun::findOrFail($id);

        if (!$run->isEditable()) {
            return response()->json(['message' => 'Only a payroll that is still a draft (or awaiting approval) can be refreshed.'], 422);
        }

        $addMissing   = $request->boolean('add_missing', true);
        $refreshBasic = $request->boolean('refresh_basic', false);
        $month        = Carbon::parse($run->period_month)->startOfMonth();

        $added = 0;
        $refreshed = 0;

        DB::transaction(function () use ($run, $month, $generator, $addMissing, $refreshBasic, &$added, &$refreshed) {
            $profiles = SalaryProfile::payable()->get()->keyBy('user_id');
            $have = $run->payslips()->pluck('user_id')->flip();

            if ($addMissing) {
                $users = User::whereIn('id', $profiles->keys())->where('user_role', 3)->get()->keyBy('id');
                $standing = PayrollSchema::table('recurring_payroll_items')
                    ? \App\Models\RecurringPayrollItem::appliesTo($month)->get()->groupBy('user_id')
                    : collect();

                foreach ($profiles as $uid => $profile) {
                    if ($have->has($uid) || !$users->has($uid)) {
                        continue;
                    }
                    $slip = $generator->createSlip($run, $profile, $users->get($uid), $month, $run->payslips()->count() + 1, Auth::id(), true, $standing->get($uid));
                    if ($slip) {
                        $added++;
                        PayrollAudit::log('payslip.added', $run->id, $slip->id, $uid, null, null, (float) $slip->net_amount);
                    }
                }
            }

            if ($refreshBasic) {
                foreach ($run->payslips()->whereIn('status', self::AWAITING)->get() as $slip) {
                    $profile = $profiles->get($slip->user_id);
                    $prorated = PayrollSchema::has('payslips', 'is_prorated') && $slip->is_prorated;
                    if (!$profile || $prorated) {
                        continue;
                    }
                    $new = (float) $profile->monthly_salary;
                    if (abs($new - (float) $slip->basic_salary) > 0.001) {
                        $old = (float) $slip->basic_salary;
                        $slip->basic_salary = $new;
                        $slip->save();
                        $slip->recalculate();
                        $refreshed++;
                        PayrollAudit::log('payslip.basic_changed', $run->id, $slip->id, $slip->user_id, (string) $old, (string) $new, $new, 'Refreshed from salary profile');
                    }
                }
            }

            $run->recalculate();
        });

        return response()->json([
            'message' => ($added || $refreshed)
                ? trim(($added ? "{$added} added" : '') . ($added && $refreshed ? ', ' : '') . ($refreshed ? "{$refreshed} refreshed" : '') . '.')
                : 'Everything is already up to date.',
            'added'     => $added,
            'refreshed' => $refreshed,
            'data'      => $run->fresh(),
        ]);
    }

    /** Marks every outstanding payslip in an approved run as paid, in one go. */
    public function markRunPaid(Request $request, $id)
    {
        if ($deny = $this->requireAdmin('record payments')) {
            return $deny;
        }

        $run = PayrollRun::findOrFail($id);

        if (!$run->isPayable()) {
            return response()->json(['message' => 'This payroll must be approved first.'], 422);
        }

        $outcome = DB::transaction(
            fn () => $this->applyToSlips($run, $run->payslips()->whereIn('status', self::AWAITING)->get(), 'pay', $this->paymentOptions($request))
        );

        PayrollAudit::log('run.paid_all', $run->id, null, null, null, null, $outcome['amount'], ['paid' => $outcome['done']]);

        return response()->json([
            'message' => $this->summaryMessage('marked as paid', $outcome),
            'data'    => $run->fresh(),
            'done'    => $outcome['done'],
            'ignored' => $outcome['ignored'],
        ]);
    }

    /**
     * One action on several payslips at once.
     *
     * Body: action = approve | unapprove | hold | release | skip | unskip | pay | unpay
     *       ids = [payslip ids]  OR  all = true (optionally narrowed by `statuses`)
     *       reason, payment_method, payment_reference, paid_at (for hold / skip / pay)
     */
    public function bulk(Request $request, $id)
    {
        if (in_array($request->input('action'), ['pay', 'unpay', 'approve', 'unapprove'], true) && ($deny = $this->requireAdmin('approve or pay payslips'))) {
            return $deny;
        }

        $data = $request->validate([
            'action'     => 'required|in:approve,unapprove,hold,release,skip,unskip,pay,unpay',
            'ids'        => 'nullable|array',
            'ids.*'      => 'integer',
            'all'        => 'nullable|boolean',
            'statuses'   => 'nullable|array',
            'statuses.*' => 'string|max:20',
            'reason'     => 'nullable|string|max:255',
        ]);

        $run = PayrollRun::findOrFail($id);

        if (!$request->boolean('all') && empty($data['ids'])) {
            return response()->json(['message' => 'Pick at least one payslip.'], 422);
        }

        $slips = $run->payslips()
            ->when(!$request->boolean('all'), fn ($q) => $q->whereIn('id', $data['ids']))
            ->when($request->boolean('all') && !empty($data['statuses']), fn ($q) => $q->whereIn('status', $data['statuses']))
            ->get();

        $opts = $this->paymentOptions($request) + ['reason' => $data['reason'] ?? null];

        $outcome = DB::transaction(fn () => $this->applyToSlips($run, $slips, $data['action'], $opts));

        $labels = [
            'approve' => 'approved', 'unapprove' => 'moved back to pending', 'hold' => 'put on hold', 'release' => 'released',
            'skip' => 'skipped', 'unskip' => 'restored', 'pay' => 'marked as paid', 'unpay' => 'payment reversed',
        ];

        return response()->json([
            'message' => $this->summaryMessage($labels[$data['action']], $outcome),
            'data'    => $run->fresh(),
            'done'    => $outcome['done'],
            'ignored' => $outcome['ignored'],
        ], ($outcome['done'] === 0 && $outcome['ignored'] ? 422 : 200));
    }

    /**
     * Adds the same bonus / deduction to many payslips — a fixed amount, or a percentage
     * of each person's basic salary (e.g. a 10% Eid bonus for everyone).
     */
    public function bulkAdjust(Request $request, $id)
    {
        $data = $request->validate([
            'kind'     => 'required|in:earning,deduction',
            'category' => 'required|string|max:80',
            'label'    => 'nullable|string|max:190',
            'mode'     => 'required|in:fixed,percent',
            'value'    => 'required|numeric|min:0.01|max:999999999',
            'ids'      => 'nullable|array',
            'ids.*'    => 'integer',
            'all'      => 'nullable|boolean',
            'notes'    => 'nullable|string|max:1000',
        ]);

        $run = PayrollRun::findOrFail($id);

        if (!$run->canAdjust()) {
            return response()->json(['message' => 'This payroll is closed or cancelled, so it can no longer be adjusted.'], 422);
        }
        if (!$request->boolean('all') && empty($data['ids'])) {
            return response()->json(['message' => 'Pick at least one payslip.'], 422);
        }
        if ($data['mode'] === 'percent' && (float) $data['value'] > 1000) {
            return response()->json(['message' => 'That percentage is too large.'], 422);
        }

        $slips = $run->payslips()
            ->when(!$request->boolean('all'), fn ($q) => $q->whereIn('id', $data['ids']))
            ->get();

        $done = 0;
        $ignored = [];
        $total = 0.0;

        DB::transaction(function () use ($run, $slips, $data, &$done, &$ignored, &$total) {
            foreach ($slips as $slip) {
                if (in_array($slip->status, ['Paid', 'Skipped'], true)) {
                    $ignored[] = ['id' => $slip->id, 'name' => $slip->employee_name, 'reason' => "Already {$slip->status}."];
                    continue;
                }

                $amount = $data['mode'] === 'percent'
                    ? round((float) $slip->basic_salary * (float) $data['value'] / 100, 2)
                    : round((float) $data['value'], 2);

                if ($amount <= 0) {
                    $ignored[] = ['id' => $slip->id, 'name' => $slip->employee_name, 'reason' => 'The amount works out to zero.'];
                    continue;
                }

                $slip->items()->create([
                    'kind'       => $data['kind'],
                    'category'   => $data['category'],
                    'label'      => $data['label'] ?? ($data['mode'] === 'percent' ? rtrim(rtrim(number_format((float) $data['value'], 2), '0'), '.') . '% of basic' : null),
                    'amount'     => $amount,
                    'notes'      => $data['notes'] ?? null,
                    'created_by' => Auth::id(),
                ]);
                $slip->recalculate();
                $done++;
                $total += $amount;

                PayrollAudit::log(
                    $run->status === PayrollRun::APPROVED ? 'payslip.adjusted_after_approval' : 'payslip.adjusted',
                    $run->id, $slip->id, $slip->user_id, null, $data['kind'] . ': ' . $data['category'], $amount, $data['label'] ?? null
                );
            }
            $run->recalculate();
        });

        return response()->json([
            'message' => $done
                ? "{$done} payslip" . ($done === 1 ? '' : 's') . ' adjusted' . ($ignored ? ' (' . count($ignored) . ' skipped).' : '.')
                : 'Nothing was adjusted.',
            'done'    => $done,
            'ignored' => $ignored,
            'data'    => $run->fresh(),
        ], $done === 0 ? 422 : 200);
    }

    /** The audit trail for one run, newest first. */
    public function activity($id)
    {
        $run = PayrollRun::findOrFail($id);

        if (!PayrollSchema::table('payroll_audit_logs')) {
            return response()->json(['supported' => false, 'data' => []]);
        }

        $rows = PayrollAuditLog::with(['actor:id,name', 'employee:id,name'])
            ->where('payroll_run_id', $run->id)
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(fn ($r) => [
                'id'        => $r->id,
                'action'    => $r->action,
                'actor'     => $r->actor?->name ?? 'System',
                'employee'  => $r->employee?->name,
                'payslip_id' => $r->payslip_id,
                'from'      => $r->from_value,
                'to'        => $r->to_value,
                'amount'    => $r->amount,
                'detail'    => $r->detail,
                'at'        => $r->created_at?->toIso8601String(),
            ]);

        return response()->json(['supported' => true, 'data' => $rows]);
    }

    /* ----------------------------- payslips ----------------------------- */

    public function showPayslip($payslipId)
    {
        $payslip = Payslip::with(['items', 'run', 'user:id,name,email,profile_pic'])->findOrFail($payslipId);

        return response()->json(['data' => $payslip]);
    }

    /** Notes / payment details / basic pay / (legacy) status while the month is open. */
    public function updatePayslip(Request $request, $payslipId)
    {
        $payslip = Payslip::with('run')->findOrFail($payslipId);

        $data = $request->validate([
            'status'            => 'nullable|in:Pending,Approved,On Hold,Skipped',
            'notes'             => 'nullable|string|max:2000',
            'payment_method'    => 'nullable|string|max:120',
            'payment_reference' => 'nullable|string|max:190',
            'basic_salary'      => 'nullable|numeric|min:0|max:999999999',
            'reason'            => 'nullable|string|max:255',
        ]);

        if (!$payslip->run || !$payslip->run->canAdjust()) {
            return response()->json(['message' => 'This payroll is closed or cancelled and can no longer be edited.'], 422);
        }

        if ($payslip->status === 'Paid') {
            return response()->json(['message' => 'A paid payslip cannot be edited.'], 422);
        }

        if (isset($data['basic_salary']) && !$payslip->run->isEditable()) {
            return response()->json(['message' => 'Basic pay can only be changed while the payroll is a draft. Reopen it first.'], 422);
        }

        if (($data['status'] ?? null) === 'Approved' && ($deny = $this->requireAdmin('approve payslips'))) {
            return $deny;
        }

        $oldBasic = (float) $payslip->basic_salary;
        $status   = $data['status'] ?? null;
        unset($data['status'], $data['reason']);

        $payslip->fill(array_filter($data, fn ($v) => $v !== null))->save();

        if ($status && $status !== $payslip->status) {
            $action = match (true) {
                $status === 'On Hold'  => 'hold',
                $status === 'Skipped'  => 'skip',
                $status === 'Approved' => 'approve',
                $status === 'Pending' && $payslip->status === 'On Hold' => 'release',
                $status === 'Pending' && $payslip->status === 'Skipped' => 'unskip',
                $status === 'Pending'  => 'unapprove',
                default => null,
            };
            if ($action) {
                $err = $this->transition($payslip->run, $payslip, $action, ['reason' => $request->input('reason')]);
                if ($err) {
                    return response()->json(['message' => $err], 422);
                }
            }
        }

        $payslip->recalculate();
        $payslip->run->recalculate();

        if (isset($data['basic_salary']) && abs($oldBasic - (float) $data['basic_salary']) > 0.001) {
            PayrollAudit::log('payslip.basic_changed', $payslip->payroll_run_id, $payslip->id, $payslip->user_id, (string) $oldBasic, (string) $data['basic_salary'], (float) $data['basic_salary']);
        }

        return response()->json(['message' => 'Payslip updated.', 'data' => $payslip->fresh('items')]);
    }

    /** One status change on one payslip (the status pill's menu). */
    public function setStatus(Request $request, $payslipId)
    {
        if (in_array($request->input('action'), ['pay', 'unpay', 'approve', 'unapprove'], true) && ($deny = $this->requireAdmin('approve or pay payslips'))) {
            return $deny;
        }

        $data = $request->validate([
            'action'            => 'required|in:approve,unapprove,hold,release,skip,unskip,pay,unpay',
            'reason'            => 'nullable|string|max:255',
            'payment_reference' => 'nullable|string|max:190',
            'payment_method'    => 'nullable|string|max:120',
            'paid_at'           => 'nullable|date',
        ]);

        $payslip = Payslip::with('run')->findOrFail($payslipId);

        $outcome = DB::transaction(
            fn () => $this->applyToSlips($payslip->run, collect([$payslip]), $data['action'], $this->paymentOptions($request) + ['reason' => $data['reason'] ?? null])
        );

        if ($outcome['done'] === 0 && $outcome['ignored']) {
            return response()->json(['message' => $outcome['ignored'][0]['reason']], 422);
        }

        return response()->json([
            'message' => 'Updated.',
            'data'    => $payslip->fresh('items'),
            'run'     => $payslip->run->fresh(),
        ]);
    }

    /** Adds a bonus / incentive / allowance / deduction line. */
    public function addItem(Request $request, $payslipId)
    {
        $payslip = Payslip::with('run')->findOrFail($payslipId);

        $data = $request->validate([
            'kind'     => 'required|in:earning,deduction',
            'category' => 'required|string|max:80',
            'label'    => 'nullable|string|max:190',
            'amount'   => 'required|numeric|min:0.01|max:999999999',
            'notes'    => 'nullable|string|max:1000',
        ]);

        if (!$payslip->run || !$payslip->run->canAdjust() || in_array($payslip->status, ['Paid'], true)) {
            return response()->json(['message' => 'This payslip can no longer be edited.'], 422);
        }

        $item = $payslip->items()->create($data + ['created_by' => Auth::id()]);

        $payslip->recalculate();
        $payslip->run->recalculate();

        PayrollAudit::log(
            $payslip->run->status === PayrollRun::APPROVED ? 'payslip.adjusted_after_approval' : 'payslip.adjusted',
            $payslip->payroll_run_id, $payslip->id, $payslip->user_id, null,
            $data['kind'] . ': ' . $data['category'], (float) $data['amount'], $data['label'] ?? null
        );

        return response()->json([
            'message' => 'Added.',
            'data'    => $item,
            'payslip' => $payslip->fresh('items'),
        ]);
    }

    public function deleteItem($payslipId, $itemId)
    {
        $payslip = Payslip::with('run')->findOrFail($payslipId);

        if (!$payslip->run || !$payslip->run->canAdjust() || $payslip->status === 'Paid') {
            return response()->json(['message' => 'This payslip can no longer be edited.'], 422);
        }

        $item = PayslipItem::where('payslip_id', $payslip->id)->where('id', $itemId)->first();
        if ($item) {
            PayrollAudit::log('payslip.item_removed', $payslip->payroll_run_id, $payslip->id, $payslip->user_id, $item->kind . ': ' . $item->category, null, (float) $item->amount, $item->label);
            $item->delete();
        }

        $payslip->recalculate();
        $payslip->run->recalculate();

        return response()->json(['message' => 'Removed.', 'payslip' => $payslip->fresh('items')]);
    }

    /**
     * Records that one person has actually been paid. Manual for now — the finance
     * team makes the transfer and pastes the reference. Only possible once approved.
     */
    public function markPaid(Request $request, $payslipId)
    {
        if ($deny = $this->requireAdmin('record payments')) {
            return $deny;
        }

        $payslip = Payslip::with('run')->findOrFail($payslipId);

        $request->validate([
            'payment_reference' => 'nullable|string|max:190',
            'payment_method'    => 'nullable|string|max:120',
            'paid_at'           => 'nullable|date',
        ]);

        $outcome = DB::transaction(fn () => $this->applyToSlips($payslip->run, collect([$payslip]), 'pay', $this->paymentOptions($request)));

        if ($outcome['done'] === 0) {
            return response()->json(['message' => $outcome['ignored'][0]['reason'] ?? 'Could not mark this payslip as paid.'], 422);
        }

        return response()->json([
            'message' => 'Marked as paid.',
            'data'    => $payslip->fresh(),
            'run'     => $payslip->run->fresh(),
        ]);
    }

    /** Undo a payment recorded by mistake. */
    public function unmarkPaid($payslipId)
    {
        if ($deny = $this->requireAdmin('reverse payments')) {
            return $deny;
        }

        $payslip = Payslip::with('run')->findOrFail($payslipId);

        $outcome = DB::transaction(fn () => $this->applyToSlips($payslip->run, collect([$payslip]), 'unpay', []));

        if ($outcome['done'] === 0) {
            return response()->json(['message' => $outcome['ignored'][0]['reason'] ?? 'This payslip is not marked paid.'], 422);
        }

        return response()->json(['message' => 'Payment reversed.', 'data' => $payslip->fresh()]);
    }

    /* ------------------------------ reports ----------------------------- */

    /**
     * Everything the finance reports need, in one response: the register of payslips for the
     * period plus roll-ups by month, team, employee, payment method and adjustment category,
     * and the list of what is still unpaid. Filters: from, to (months), status, user_id,
     * team, method, run_status.
     */
    public function report(Request $request)
    {
        $from = $request->filled('from') ? Carbon::parse($request->input('from'))->startOfMonth() : Carbon::today()->startOfYear();
        $to   = $request->filled('to') ? Carbon::parse($request->input('to'))->endOfMonth() : Carbon::today()->endOfMonth();

        $rows = Payslip::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->whereNull('payroll_runs.deleted_at')
            ->where('payroll_runs.status', '!=', PayrollRun::CANCELLED)
            ->whereBetween('payslips.period_month', [$from->toDateString(), $to->toDateString()])
            ->when($request->filled('status'), fn ($q) => $q->where('payslips.status', $request->input('status')),
                fn ($q) => $q->where('payslips.status', '!=', 'Skipped'))
            ->when($request->filled('user_id'), fn ($q) => $q->where('payslips.user_id', $request->input('user_id')))
            ->when($request->filled('team'), fn ($q) => $request->input('team') === '__none'
                ? $q->where(fn ($w) => $w->whereNull('payslips.department')->orWhere('payslips.department', ''))
                : $q->where('payslips.department', $request->input('team')))
            ->when($request->filled('method'), fn ($q) => $q->where('payslips.payment_method', $request->input('method')))
            ->when($request->filled('run_status'), fn ($q) => $q->where('payroll_runs.status', $request->input('run_status')))
            ->select('payslips.*', 'payroll_runs.status as run_status')
            ->orderByDesc('payslips.period_month')
            ->orderBy('payslips.employee_name')
            ->get();

        $sum = function ($g) {
            $awaiting = $g->whereIn('status', self::AWAITING);
            $held = $g->where('status', 'On Hold');
            $paid = $g->where('status', 'Paid');

            return [
                'payslips'          => $g->count(),
                'employees'         => $g->pluck('user_id')->unique()->count(),
                'basic'             => round((float) $g->sum('basic_salary'), 2),
                'earnings'          => round((float) $g->sum('total_earnings'), 2),
                'gross'             => round((float) $g->sum('gross_amount'), 2),
                'deductions'        => round((float) $g->sum('total_deductions'), 2),
                'net'               => round((float) $g->sum('net_amount'), 2),
                'paid'              => round((float) $paid->sum('net_amount'), 2),
                'paid_count'        => $paid->count(),
                'outstanding'       => round((float) $awaiting->sum('net_amount'), 2),
                'outstanding_count' => $awaiting->count(),
                'held'              => round((float) $held->sum('net_amount'), 2),
                'held_count'        => $held->count(),
            ];
        };

        // Month roll-up, including empty months so a chart has no gaps.
        $months = [];
        for ($m = $from->copy(); $m->lte($to); $m->addMonth()) {
            $key = $m->format('Y-m');
            $group = $rows->filter(fn ($r) => Carbon::parse($r->period_month)->format('Y-m') === $key);
            $months[] = ['month' => $key] + $sum($group);
        }

        $byTeam = $rows->groupBy(fn ($r) => trim((string) $r->department) !== '' ? $r->department : 'Unassigned')
            ->map(fn ($g, $team) => ['team' => $team] + $sum($g))
            ->sortByDesc('net')->values();

        $byEmployee = $rows->groupBy('user_id')
            ->map(function ($g) use ($sum) {
                $latest = $g->sortByDesc('period_month')->first();

                return [
                    'user_id'     => $latest->user_id,
                    'name'        => $latest->employee_name,
                    'team'        => $latest->department,
                    'designation' => $latest->designation,
                    'months'      => $g->count(),
                ] + $sum($g);
            })
            ->sortBy('name')->values();

        $byMethod = $rows->where('status', 'Paid')
            ->groupBy(fn ($r) => trim((string) $r->payment_method) !== '' ? $r->payment_method : 'Not recorded')
            ->map(fn ($g, $method) => ['method' => $method, 'payments' => $g->count(), 'amount' => round((float) $g->sum('net_amount'), 2)])
            ->sortByDesc('amount')->values();

        $items = PayslipItem::whereIn('payslip_id', $rows->pluck('id'))->get(['payslip_id', 'kind', 'category', 'amount']);
        $byCategory = $items->groupBy(fn ($i) => $i->kind . '|' . $i->category)
            ->map(function ($g) {
                $first = $g->first();

                return [
                    'kind'      => $first->kind,
                    'category'  => $first->category,
                    'count'     => $g->count(),
                    'employees' => $g->pluck('payslip_id')->unique()->count(),
                    'total'     => round((float) $g->sum('amount'), 2),
                ];
            })
            ->sortByDesc('total')->values();

        $today = Carbon::today();
        $outstanding = $rows->whereIn('status', array_merge(self::AWAITING, ['On Hold']))
            ->map(fn ($r) => [
                'id'          => $r->id,
                'run_id'      => $r->payroll_run_id,
                'user_id'     => $r->user_id,
                'name'        => $r->employee_name,
                'team'        => $r->department,
                'period'      => Carbon::parse($r->period_month)->format('Y-m'),
                'net'         => (float) $r->net_amount,
                'currency'    => $r->currency,
                'status'      => $r->status,
                'run_status'  => $r->run_status,
                'age_days'    => max(0, (int) Carbon::parse($r->period_month)->endOfMonth()->diffInDays($today, false)),
            ])->sortByDesc('age_days')->values();

        $register = $rows->map(fn ($r) => [
            'id'                => $r->id,
            'run_id'            => $r->payroll_run_id,
            'payslip_no'        => $r->payslip_no,
            'user_id'           => $r->user_id,
            'name'              => $r->employee_name,
            'team'              => $r->department,
            'designation'       => $r->designation,
            'period'            => Carbon::parse($r->period_month)->format('Y-m'),
            'basic'             => (float) $r->basic_salary,
            'earnings'          => (float) $r->total_earnings,
            'gross'             => (float) $r->gross_amount,
            'deductions'        => (float) $r->total_deductions,
            'net'               => (float) $r->net_amount,
            'currency'          => $r->currency,
            'status'            => $r->status,
            'run_status'        => $r->run_status,
            'paid_at'           => $r->paid_at,
            'payment_method'    => $r->payment_method,
            'payment_reference' => $r->payment_reference,
        ])->values();

        $currencies = $rows->pluck('currency')->filter()->unique()->values();

        return response()->json([
            'data'        => $register,
            'months'      => collect($months)->map(fn ($m) => $m + ['employees' => $m['employees']])->values(),
            'by_team'     => $byTeam,
            'by_employee' => $byEmployee,
            'by_method'   => $byMethod,
            'by_category' => $byCategory,
            'outstanding' => $outstanding,
            'range'       => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'currency'    => $currencies->first() ?? 'PKR',
            'mixed_currency' => $currencies->count() > 1,
            'totals'      => $sum($rows),
        ]);
    }

    /* ------------------------------ internals ---------------------------- */

    private function paymentOptions(Request $request): array
    {
        return [
            'payment_reference' => $request->input('payment_reference'),
            'payment_method'    => $request->input('payment_method'),
            'paid_at'           => $request->filled('paid_at') ? Carbon::parse($request->input('paid_at')) : now(),
        ];
    }

    private function approveRun(PayrollRun $run): void
    {
        PayrollWorkflow::approve($run, Auth::id());
    }

    /**
     * Approving, paying and closing are the admin's call. Executives can prepare a payroll
     * (generate, adjust, hold, skip, refresh) and SEND IT FOR APPROVAL, but not approve it.
     */
    private function requireAdmin(string $what = 'do this')
    {
        if ((int) (Auth::user()->user_role ?? 0) === 2) {
            return null;
        }

        return response()->json(['message' => "Only an admin can {$what}. Use “Request approval” to send this payroll to the approver."], 403);
    }

    private function summaryMessage(string $verb, array $outcome): string
    {
        $n = $outcome['done'];
        $msg = $n . ' payslip' . ($n === 1 ? '' : 's') . ' ' . $verb . '.';
        if (count($outcome['ignored'])) {
            $msg .= ' ' . count($outcome['ignored']) . ' left as they were.';
        }

        return $msg;
    }

    /**
     * Applies one action to many payslips and returns what happened.
     *
     * @param  \Illuminate\Support\Collection<int, Payslip>  $slips
     * @return array{done: int, ignored: array<int, array<string, mixed>>, amount: float}
     */
    private function applyToSlips(PayrollRun $run, $slips, string $action, array $opts): array
    {
        $done = 0;
        $amount = 0.0;
        $ignored = [];
        $paid = collect();

        foreach ($slips as $slip) {
            $err = $this->transition($run, $slip, $action, $opts);

            if ($err) {
                $ignored[] = ['id' => $slip->id, 'name' => $slip->employee_name, 'reason' => $err];
                continue;
            }

            $done++;
            $amount += (float) $slip->net_amount;
            if ($action === 'pay') {
                $paid->push($slip);
            }
        }

        $run->refresh()->recalculate();

        if ($paid->isNotEmpty()) {
            $this->notifyPaid($run, $paid);
        }

        return ['done' => $done, 'ignored' => $ignored, 'amount' => round($amount, 2)];
    }

    /**
     * The single place a payslip changes status. Returns an error sentence (and changes
     * nothing) when the move is not allowed, or null when it was applied.
     */
    private function transition(PayrollRun $run, Payslip $slip, string $action, array $opts): ?string
    {
        if (!$run || in_array($run->status, [PayrollRun::CANCELLED], true)) {
            return 'This payroll is cancelled.';
        }
        if ($run->isClosed()) {
            return 'This month is closed. Reopen the period first.';
        }

        $from = $slip->status;
        $reason = isset($opts['reason']) ? trim((string) $opts['reason']) : null;
        $restored = in_array($run->status, [PayrollRun::APPROVED, PayrollRun::PAID], true) ? 'Approved' : 'Pending';
        $fields = [];
        $auditAction = null;

        switch ($action) {
            case 'approve':
                if ($from !== 'Pending') {
                    return "Only pending payslips can be approved (this one is {$from}).";
                }
                $fields = ['status' => 'Approved', 'approved_by' => Auth::id(), 'approved_at' => now()];
                $auditAction = 'payslip.approved';
                break;

            case 'unapprove':
                if ($from !== 'Approved') {
                    return "Only approved payslips can be moved back to pending (this one is {$from}).";
                }
                if ($run->isPayable()) {
                    return 'The payroll is approved. Reopen it to move a payslip back to pending.';
                }
                $fields = ['status' => 'Pending', 'approved_by' => null, 'approved_at' => null];
                $auditAction = 'payslip.unapproved';
                break;

            case 'hold':
                if (!in_array($from, self::AWAITING, true)) {
                    return "A {$from} payslip cannot be put on hold.";
                }
                $fields = ['status' => 'On Hold', 'status_reason' => $reason];
                $auditAction = 'payslip.held';
                break;

            case 'release':
                if ($from !== 'On Hold') {
                    return "Only payslips on hold can be released (this one is {$from}).";
                }
                $fields = ['status' => $restored, 'status_reason' => null];
                $auditAction = 'payslip.released';
                break;

            case 'skip':
                if (!in_array($from, array_merge(self::AWAITING, ['On Hold']), true)) {
                    return "A {$from} payslip cannot be skipped.";
                }
                $fields = ['status' => 'Skipped', 'status_reason' => $reason];
                $auditAction = 'payslip.skipped';
                break;

            case 'unskip':
                if ($from !== 'Skipped') {
                    return "Only skipped payslips can be restored (this one is {$from}).";
                }
                $fields = ['status' => $restored, 'status_reason' => null];
                $auditAction = 'payslip.unskipped';
                break;

            case 'pay':
                if (!$run->isPayable()) {
                    return 'This payroll must be approved before payments can be recorded.';
                }
                if ($from === 'Paid') {
                    return 'Already marked as paid.';
                }
                if (!in_array($from, self::AWAITING, true)) {
                    return "A {$from} payslip cannot be paid. " . ($from === 'On Hold' ? 'Release it first.' : '');
                }
                $fields = [
                    'status'            => 'Paid',
                    'paid_at'           => $opts['paid_at'] ?? now(),
                    'payment_reference' => ($opts['payment_reference'] ?? null) ?: $slip->payment_reference,
                    'payment_method'    => ($opts['payment_method'] ?? null) ?: $slip->payment_method,
                    'paid_by'           => Auth::id(),
                ];
                $auditAction = 'payslip.paid';
                break;

            case 'unpay':
                if ($from !== 'Paid') {
                    return 'This payslip is not marked paid.';
                }
                $fields = ['status' => 'Approved', 'paid_at' => null, 'paid_by' => null];
                $auditAction = 'payslip.unpaid';
                break;

            default:
                return 'Unknown action.';
        }

        $slip->fill(PayrollSchema::only('payslips', $fields))->save();

        PayrollAudit::log(
            $auditAction, $run->id, $slip->id, $slip->user_id, $from, $slip->status,
            (float) $slip->net_amount,
            $reason ?: (($opts['payment_reference'] ?? null) && $action === 'pay' ? 'ref ' . $opts['payment_reference'] : null)
        );

        return null;
    }

    /** Tells each paid employee, in the portal, that their salary went out. Never blocks the payment. */
    private function notifyPaid(PayrollRun $run, $slips): void
    {
        try {
            $label = Carbon::parse($run->period_month)->format('F Y');
            foreach ($slips as $slip) {
                insertNotificationWithNature(
                    $slip->user_id,
                    Auth::id() ?? $slip->user_id,
                    'payslip_paid',
                    'Your salary for ' . $label . ' has been paid.',
                    'success',
                    0
                );
            }
        } catch (\Throwable $e) {
            // notifications are a courtesy; the payment is already recorded
        }
    }
}
