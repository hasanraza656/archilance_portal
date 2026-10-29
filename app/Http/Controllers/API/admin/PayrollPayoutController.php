<?php

namespace App\Http\Controllers\API\admin;

use App\Http\Controllers\Controller;
use App\Models\PayrollAuditLog;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\PayrollApprovals;
use App\Services\PayrollAudit;
use App\Support\PayrollAccess;
use App\Support\PayrollSchema;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The local-payout list.
 *
 * Some people cannot be paid through Payoneer (their Payoneer account is not approved yet, so a payment would get
 * stuck). Their salary profile is marked "local", and at month-end the person who makes local payments (NayaPay /
 * SadaPay) gets one list of exactly who to pay, how much and into which account: as an email with the PDF attached.
 *
 * The PDF is built in the browser from the same data (like the payroll approval report); the email itself also carries
 * the whole list, so it is complete even if the PDF is missing.
 */
class PayrollPayoutController extends Controller
{
    private const MAX_PDF_BYTES = 6 * 1024 * 1024;
    private const ACTION = 'run.local_list_sent';

    private function ready(): bool
    {
        return PayrollSchema::has('payslips', 'payout_route');
    }

    /** Slips that still have to be paid through a local account: local route, waiting for payment, visible to the asker. */
    private function localSlips(PayrollRun $run)
    {
        return PayrollAccess::visibleSlips(Payslip::query())
            ->where('payroll_run_id', $run->id)
            ->where('payout_route', 'local')
            ->whereIn('status', ['Pending', 'Approved'])
            ->orderBy('employee_name')
            ->get();
    }

    private function present($slips): array
    {
        $accounts = \App\Models\SalaryProfile::whereIn('user_id', $slips->pluck('user_id'))->get()->keyBy('user_id');

        return $slips->map(function ($s) use ($accounts) {
            $profile = $accounts->get($s->user_id);

            return [
                'id'       => $s->id,
                'name'     => $s->employee_name,
                'team'     => $s->department,
                'method'   => $profile->payment_method ?? $s->payment_method,
                'account'  => $profile->payment_reference ?? null,
                'amount'   => (float) $s->net_amount,
                'currency' => $s->currency ?: 'PKR',
                'status'   => $s->status,
            ];
        })->values()->all();
    }

    private function totals(array $items): array
    {
        $by = [];
        foreach ($items as $i) {
            $by[$i['currency']] = ($by[$i['currency']] ?? 0) + $i['amount'];
        }
        $out = [];
        foreach ($by as $cur => $sum) {
            $out[] = ['currency' => $cur, 'total' => round($sum, 2)];
        }

        return $out;
    }

    /** GET /payroll/{id}/local-payouts: who is on the list, who it goes to, and when it was last sent. */
    public function show($id)
    {
        $run = PayrollRun::findOrFail($id);

        if (!$this->ready()) {
            return response()->json(['supported' => false, 'message' => 'Local payouts need the latest database update (System settings → Update database).', 'items' => []]);
        }

        $items = $this->present($this->localSlips($run));

        $last = PayrollSchema::table('payroll_audit_logs')
            ? PayrollAuditLog::with('actor:id,name')->where('payroll_run_id', $run->id)->where('action', self::ACTION)->orderByDesc('id')->first()
            : null;

        return response()->json([
            'supported'  => true,
            'items'      => $items,
            'totals'     => $this->totals($items),
            'recipients' => PayrollApprovals::localPayoutEmails(),
            'restricted' => PayrollAccess::restricted(),
            'last_sent'  => $last ? ['at' => $last->created_at?->toIso8601String(), 'by' => $last->actor?->name, 'detail' => $last->detail] : null,
        ]);
    }

    /** POST /payroll/{id}/local-payouts: emails the list (with the PDF if the browser built one). */
    public function send(Request $request, $id)
    {
        $data = $request->validate([
            'message'    => 'nullable|string|max:1000',
            'pdf_base64' => 'nullable|string',
        ]);

        $run = PayrollRun::findOrFail($id);

        if (!$this->ready()) {
            return response()->json(['message' => 'Run the latest database update first (System settings → Update database).'], 503);
        }
        if (in_array($run->status, [PayrollRun::CANCELLED], true)) {
            return response()->json(['message' => 'This payroll is cancelled.'], 422);
        }

        $items = $this->present($this->localSlips($run));
        if (!$items) {
            return response()->json(['message' => 'Nobody is waiting for a local payment in this payroll.'], 422);
        }

        $pdfBytes = null;
        if (!empty($data['pdf_base64'])) {
            $raw = preg_replace('#^data:application/pdf;base64,#', '', $data['pdf_base64']);
            $decoded = base64_decode($raw, true);
            if ($decoded !== false && strlen($decoded) > 100 && strlen($decoded) <= self::MAX_PDF_BYTES && str_starts_with($decoded, '%PDF')) {
                $pdfBytes = $decoded;
            }
        }

        $period = Carbon::parse($run->period_month)->format('F Y');
        $totals = $this->totals($items);
        $totalText = implode(' + ', array_map(fn ($t) => $t['currency'] . ' ' . number_format($t['total'], 2), $totals));
        $sender = Auth::user();

        $details = [];
        foreach (array_slice($items, 0, 80) as $i) {
            $details[] = [
                'label' => $i['name'],
                'value' => $i['currency'] . ' ' . number_format($i['amount'], 2) . (($i['method'] || $i['account']) ? '  ·  ' . trim(($i['method'] ?? '') . ' ' . ($i['account'] ?? '')) : '  ·  no account recorded'),
            ];
        }
        if (count($items) > 80) {
            $details[] = ['label' => '…', 'value' => (count($items) - 80) . ' more are in the attached PDF'];
        }
        $details[] = ['label' => 'Total to pay locally', 'value' => $totalText];

        $sent = [];
        $allOk = true;
        foreach (PayrollApprovals::localPayoutEmails() as $email) {
            $ok = PayrollApprovals::send($email, "Local payouts for {$period} (" . count($items) . ' people, ' . $totalText . ')', [
                'emoji'        => '💸',
                'heading'      => 'Local payouts to make',
                'greetingName' => PayrollApprovals::firstNameFor($email),
                'intro'        => 'These people could not be paid through Payoneer for <strong>' . e($period) . '</strong>, so they need to be paid from a local account (NayaPay / SadaPay). The list is below'
                    . ($pdfBytes ? ' and attached as a PDF.' : '.'),
                'quote'        => $data['message'] ?? null,
                'quoteAuthor'  => !empty($data['message']) ? $sender->name : null,
                'details'      => $details,
                'signoff'      => 'Sent by ' . $sender->name . ' from the Archilance portal. Please tell them once everyone is paid so it can be marked as paid.',
            ], $pdfBytes, 'Local-payouts-' . Carbon::parse($run->period_month)->format('Y-m') . '.pdf');
            $sent[] = $email;
            $allOk = $allOk && $ok;
        }

        PayrollAudit::log(
            self::ACTION, $run->id, null, null, null, implode(', ', $sent), null,
            count($items) . ' people · ' . $totalText . ($allOk ? '' : ' · email failed')
        );

        return response()->json([
            'message' => $allOk
                ? 'Sent to ' . implode(', ', $sent) . ' (' . count($items) . ' people' . ($pdfBytes ? ', PDF attached' : '') . ').'
                : 'The email could not be sent. Please try again or send the PDF by hand.',
            'ok'      => $allOk,
            'sent_to' => $sent,
            'count'   => count($items),
            'pdf_attached' => (bool) $pdfBytes,
        ], $allOk ? 200 : 502);
    }
}
