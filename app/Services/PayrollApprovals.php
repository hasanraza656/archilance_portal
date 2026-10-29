<?php

namespace App\Services;

use App\Models\PayrollApprovalRequest;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\PayrollSchema;
use Carbon\Carbon;

/**
 * Emails and in-app notices around payroll approval requests.
 *
 * Everything here is best-effort: a failed email never blocks the approval flow
 * (it is logged, and the request records whether the email went out).
 */
class PayrollApprovals
{
    /** Where approval requests are emailed. Override with PAYROLL_APPROVER_EMAIL (comma-separated). */
    public static function approverEmails(): array
    {
        $raw = (string) env('PAYROLL_APPROVER_EMAIL', 'faran@archilance.net');

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /** Where the local-payout list is emailed (the person who pays through local accounts). PAYROLL_LOCAL_PAYOUT_EMAIL, comma-separated. */
    public static function localPayoutEmails(): array
    {
        $raw = (string) env('PAYROLL_LOCAL_PAYOUT_EMAIL', 'asad@archilance.net');

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public static function firstNameFor(string $email): string
    {
        return self::nameFor($email);
    }

    private static function money($n): string
    {
        return number_format((float) $n, 2);
    }

    private static function period(PayrollRun $run): string
    {
        return Carbon::parse($run->period_month)->format('F Y');
    }

    private static function nameFor(string $email): string
    {
        $user = User::where('email', $email)->first();
        if ($user && trim((string) $user->name) !== '') {
            return explode(' ', trim($user->name))[0];
        }

        return ucfirst(explode('@', $email)[0]);
    }

    /** One email, optionally with a PDF attached. Returns whether it was handed to the mailer. */
    public static function send(string $to, string $subject, array $data, ?string $pdfBytes = null, ?string $pdfName = null): bool
    {
        try {
            \Mail::send('mails.notification', $data, function ($message) use ($to, $subject, $pdfBytes, $pdfName) {
                $message->from('info@archilance.net', 'Archilance LLC')->to($to)->subject($subject);
                if ($pdfBytes) {
                    $message->attachData($pdfBytes, $pdfName ?: 'payroll-report.pdf', ['mime' => 'application/pdf']);
                }
            });

            return true;
        } catch (\Throwable $e) {
            \Log::warning('Payroll approval email to ' . $to . ' failed: ' . $e->getMessage());

            return false;
        }
    }

    /** Emails the approver(s) and pings every admin in the portal. Returns [emailedTo[], allOk]. */
    public static function notifyRequested(PayrollApprovalRequest $req, PayrollRun $run, User $requester, ?string $pdfBytes): array
    {
        $period = self::period($run);
        $subject = "Payroll approval requested — {$period} ({$req->employee_count} employees, PKR " . self::money($req->total_net) . ')';
        $pdfName = 'Payroll-' . Carbon::parse($run->period_month)->format('Y-m') . '.pdf';

        $details = [
            ['label' => 'Period', 'value' => $period],
            ['label' => 'Employees', 'value' => (string) $req->employee_count],
            ['label' => 'Gross pay', 'value' => 'PKR ' . self::money($run->total_gross)],
            ['label' => 'Deductions', 'value' => 'PKR ' . self::money($run->total_deductions)],
            ['label' => 'Net payable', 'value' => 'PKR ' . self::money($req->total_net)],
            ['label' => 'Requested by', 'value' => $requester->name],
            ['label' => 'Status', 'badge' => ['text' => 'Awaiting approval', 'kind' => 'status']],
        ];

        $sent = [];
        $allOk = true;

        foreach (self::approverEmails() as $email) {
            $ok = self::send($email, $subject, [
                'emoji' => '🧾',
                'heading' => 'Payroll approval requested',
                'greetingName' => self::nameFor($email),
                'intro' => '<strong>' . e($requester->name) . '</strong> has sent the <strong>' . e($period) . '</strong> payroll for your approval.'
                    . ($pdfBytes ? ' The complete report is attached as a PDF.' : ''),
                'quote' => $req->message ?: null,
                'quoteAuthor' => $req->message ? $requester->name : null,
                'details' => $details,
                'cta' => ['text' => 'Review & approve', 'url' => frontendUrl('/v2/payroll/' . $run->id)],
                'signoff' => 'You can approve it as it is, change anything first, or send it back with a note.',
            ], $pdfBytes, $pdfName);

            $sent[] = $email;
            $allOk = $allOk && $ok;
        }

        // In the portal: every admin sees it too.
        try {
            $admins = User::where('user_role', 2)->get(['id']);
            foreach ($admins as $a) {
                insertNotificationWithNature(
                    $a->id,
                    $requester->id,
                    'payroll_approval_request',
                    "{$requester->name} sent the {$period} payroll for your approval.",
                    'info',
                    0
                );
            }
        } catch (\Throwable $e) {
            // courtesy only
        }

        return [$sent, $allOk];
    }

    /** Tells the requester what the admin decided (email + portal notice). */
    public static function notifyDecision(PayrollApprovalRequest $req, PayrollRun $run, User $decider, string $decision): void
    {
        $requester = $req->requester;
        if (!$requester) {
            return;
        }

        $period = self::period($run);
        $copy = [
            'approved' => ['✅', 'Payroll approved', "{$decider->name} approved the <strong>" . e($period) . '</strong> payroll. It can now be paid.', 'Approved'],
            'changes' => ['✏️', 'Changes requested', "{$decider->name} sent the <strong>" . e($period) . '</strong> payroll back with changes to make. It is a draft again — update it and send it for approval once more.', 'Changes requested'],
            'rejected' => ['⛔', 'Payroll not approved', "{$decider->name} did not approve the <strong>" . e($period) . '</strong> payroll.', 'Not approved'],
        ][$decision] ?? null;

        if (!$copy) {
            return;
        }

        [$emoji, $heading, $intro, $badge] = $copy;

        sendNotificationEmail($requester, "{$heading} — {$period} payroll", [
            'emoji' => $emoji,
            'heading' => $heading,
            'intro' => $intro,
            'quote' => $req->decision_note ?: null,
            'quoteAuthor' => $req->decision_note ? $decider->name : null,
            'details' => [
                ['label' => 'Period', 'value' => $period],
                ['label' => 'Net payable', 'value' => 'PKR ' . self::money($req->total_net)],
                ['label' => 'Decision', 'badge' => ['text' => $badge, 'kind' => 'status']],
            ],
            'cta' => ['text' => 'Open payroll', 'url' => frontendUrl('/v2/payroll/' . $run->id)],
        ]);

        try {
            insertNotificationWithNature(
                $requester->id,
                $decider->id,
                'payroll_approval_decision',
                "{$heading}: the {$period} payroll" . ($req->decision_note ? " — {$req->decision_note}" : '.'),
                $decision === 'approved' ? 'success' : 'warning',
                0
            );
        } catch (\Throwable $e) {
            // courtesy only
        }
    }

    /**
     * An admin approved the run by any route (the plain Approve button, or "Approve & pay"):
     * close any open request for it, and tell whoever asked.
     */
    public static function resolveOnApproval(PayrollRun $run, ?int $actorId): void
    {
        if (!PayrollSchema::table('payroll_approval_requests')) {
            return;
        }

        try {
            $open = PayrollApprovalRequest::with('requester')->where('payroll_run_id', $run->id)->where('status', PayrollApprovalRequest::PENDING)->get();
            $decider = $actorId ? User::find($actorId) : null;

            foreach ($open as $req) {
                $req->update(['status' => PayrollApprovalRequest::APPROVED, 'decided_by' => $actorId, 'decided_at' => now()]);
                if ($decider && (int) $req->requested_by !== (int) $actorId) {
                    self::notifyDecision($req, $run, $decider, 'approved');
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Could not close approval requests for run ' . $run->id . ': ' . $e->getMessage());
        }
    }
}
