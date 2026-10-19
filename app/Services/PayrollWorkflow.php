<?php

namespace App\Services;

use App\Models\PayrollRun;
use App\Support\PayrollSchema;
use Illuminate\Support\Facades\DB;

/**
 * State changes of a payroll run that more than one screen triggers (the Approve
 * button, and an admin approving an executive's request), kept in one place so
 * both do exactly the same thing and write the same audit trail.
 */
class PayrollWorkflow
{
    /** Draft / Pending Approval → Approved, approving every pending payslip with it. */
    public static function approve(PayrollRun $run, ?int $actorId): void
    {
        DB::transaction(function () use ($run, $actorId) {
            $run->recalculate(false);
            $run->status      = PayrollRun::APPROVED;
            $run->approved_by = $actorId;
            $run->approved_at = now();
            $run->save();

            $run->payslips()->where('status', 'Pending')->update(
                PayrollSchema::only('payslips', ['status' => 'Approved', 'approved_by' => $actorId, 'approved_at' => now()])
            );
        });

        PayrollAudit::log('run.approved', $run->id, null, null, null, PayrollRun::APPROVED, (float) $run->total_net, null, $actorId);

        // Any open approval request for this run is now answered.
        PayrollApprovals::resolveOnApproval($run, $actorId);
    }

    /** Pending Approval → Draft, so the payroll can be corrected and sent again. */
    public static function backToDraft(PayrollRun $run, ?int $actorId, string $why): void
    {
        $from = $run->status;
        $run->update(['status' => PayrollRun::DRAFT]);

        PayrollAudit::log('run.returned_to_draft', $run->id, null, null, $from, PayrollRun::DRAFT, null, $why, $actorId);
    }
}
