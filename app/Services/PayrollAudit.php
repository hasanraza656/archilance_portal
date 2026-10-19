<?php

namespace App\Services;

use App\Models\PayrollAuditLog;
use App\Support\PayrollSchema;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the payroll audit trail: who did what, when, and the before -> after.
 *
 * Best-effort by design — a failure to LOG must never undo or block the payroll
 * action itself — and a no-op until the audit table exists.
 */
class PayrollAudit
{
    public static function log(
        string $action,
        ?int $runId = null,
        ?int $payslipId = null,
        ?int $employeeId = null,
        ?string $from = null,
        ?string $to = null,
        ?float $amount = null,
        $detail = null,
        ?int $actorId = null
    ): void {
        if (!PayrollSchema::table('payroll_audit_logs')) {
            return;
        }

        try {
            PayrollAuditLog::create([
                'payroll_run_id' => $runId,
                'payslip_id' => $payslipId,
                'user_id' => $employeeId,
                'actor_id' => $actorId ?? Auth::id(),
                'action' => $action,
                'from_value' => $from !== null ? mb_substr($from, 0, 190) : null,
                'to_value' => $to !== null ? mb_substr($to, 0, 190) : null,
                'amount' => $amount,
                'detail' => is_array($detail) ? json_encode($detail) : $detail,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // never let the audit write break the action it describes
        }
    }
}
