<?php

namespace App\Http\Controllers\API\admin;

use App\Http\Controllers\Controller;
use App\Models\PayrollApprovalRequest;
use App\Models\PayrollRun;
use App\Services\PayrollApprovals;
use App\Services\PayrollAudit;
use App\Services\PayrollWorkflow;
use App\Support\PayrollSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Payroll approval requests.
 *
 * An executive prepares a payroll and sends it for approval: the approver(s) get an
 * email with the full PDF report attached, every admin gets a portal notice, and the
 * request waits on the payroll page. An admin can then approve it, send it back with
 * changes, or reject it — and is free to edit the payroll first.
 *
 *   POST /payroll/{id}/request-approval        executives + admins
 *   GET  /payroll/{id}/approval-requests       history for one payroll
 *   GET  /payroll-approval-requests            what is waiting (admins: everything, others: their own)
 *   GET  /payroll-approval-requests/{id}/pdf   the PDF that was emailed
 *   POST /payroll-approval-requests/{id}/decide  admin only: approve | changes | reject
 *
 * Until the payroll_approvals database update is applied (System settings → Update
 * database) every endpoint answers "not available yet" instead of failing.
 */
class PayrollApprovalController extends Controller
{
    private const MAX_PDF_BYTES = 6 * 1024 * 1024;

    private function unavailable()
    {
        return PayrollSchema::table('payroll_approval_requests')
            ? null
            : response()->json(['supported' => false, 'message' => 'Approval requests need the latest database update (System settings → Update database).', 'data' => []], 200);
    }

    private function isAdmin(): bool
    {
        return (int) (Auth::user()->user_role ?? 0) === 2;
    }

    private function present(PayrollApprovalRequest $r): array
    {
        // Someone who may not see executives' salaries gets the total of the payslips they CAN see, not the stored total.
        $totalNet = (float) $r->total_net;
        $count = $r->employee_count;
        if (\App\Support\PayrollAccess::restricted() && $r->run) {
            \App\Support\PayrollAccess::restrictRuns([$r->run]);
            $totalNet = (float) $r->run->total_net;
            $count = $r->run->employee_count;
        }

        return [
            'id'            => $r->id,
            'payroll_run_id' => $r->payroll_run_id,
            'status'        => $r->status,
            'message'       => $r->message,
            'total_net'     => $totalNet,
            'employee_count' => $count,
            'requested_by'  => $r->requester ? ['id' => $r->requester->id, 'name' => $r->requester->name] : null,
            'decided_by'    => $r->decider ? ['id' => $r->decider->id, 'name' => $r->decider->name] : null,
            'decided_at'    => $r->decided_at?->toIso8601String(),
            'decision_note' => $r->decision_note,
            'emailed_to'    => $r->emailed_to,
            'email_ok'      => (bool) $r->email_ok,
            'has_pdf'       => (bool) $r->pdf_path,
            'created_at'    => $r->created_at?->toIso8601String(),
            'period_month'  => $r->run?->period_month?->toDateString(),
            'run_title'     => $r->run?->title,
            'run_status'    => $r->run?->status,
        ];
    }

    public function requestApproval(Request $request, $id)
    {
        if ($r = $this->unavailable()) {
            return response()->json($r->getData(true), 503);
        }

        $data = $request->validate([
            'message'    => 'nullable|string|max:1000',
            'pdf_base64' => 'nullable|string',
        ]);

        $run = PayrollRun::findOrFail($id);

        if (!in_array($run->status, [PayrollRun::DRAFT, PayrollRun::PENDING], true)) {
            return response()->json(['message' => 'This payroll is already ' . strtolower($run->status) . ', so it cannot be sent for approval.'], 422);
        }

        $run->recalculate();
        if ((int) $run->employee_count === 0) {
            return response()->json(['message' => 'There is nothing in this payroll to approve yet.'], 422);
        }

        // The PDF report (built in the browser) — checked, never trusted blindly.
        $pdfBytes = null;
        if (!empty($data['pdf_base64'])) {
            $raw = preg_replace('#^data:application/pdf;base64,#', '', $data['pdf_base64']);
            $decoded = base64_decode($raw, true);
            if ($decoded !== false && strlen($decoded) > 100 && strlen($decoded) <= self::MAX_PDF_BYTES && str_starts_with($decoded, '%PDF')) {
                $pdfBytes = $decoded;
            }
        }

        $requester = Auth::user();

        $req = \Illuminate\Support\Facades\DB::transaction(function () use ($run, $data, $requester, $pdfBytes) {
            PayrollApprovalRequest::where('payroll_run_id', $run->id)
                ->where('status', PayrollApprovalRequest::PENDING)
                ->update(['status' => PayrollApprovalRequest::SUPERSEDED]);

            $path = null;
            if ($pdfBytes) {
                $path = 'payroll-requests/run-' . $run->id . '-' . now()->format('YmdHis') . '.pdf';
                Storage::disk('local')->put($path, $pdfBytes);
            }

            $req = PayrollApprovalRequest::create([
                'payroll_run_id' => $run->id,
                'requested_by'   => $requester->id,
                'status'         => PayrollApprovalRequest::PENDING,
                'message'        => $data['message'] ?? null,
                'pdf_path'       => $path,
                'total_net'      => (float) $run->total_net,
                'employee_count' => (int) $run->employee_count,
            ]);

            if ($run->status === PayrollRun::DRAFT) {
                $run->update(['status' => PayrollRun::PENDING]);
            }

            return $req;
        });

        PayrollAudit::log('run.approval_requested', $run->id, null, null, null, 'Awaiting approval', (float) $run->total_net, $data['message'] ?? null);

        [$sent, $allOk] = PayrollApprovals::notifyRequested($req, $run->fresh(), $requester, $pdfBytes);
        $req->update(['emailed_to' => implode(', ', $sent), 'email_ok' => $allOk]);

        return response()->json([
            'message' => $allOk
                ? 'Sent for approval. ' . implode(', ', $sent) . ' has been emailed' . ($pdfBytes ? ' with the PDF report.' : '.')
                : 'The request is saved and visible to the admins, but the email could not be sent. Please tell the approver.',
            'data'    => $this->present($req->fresh(['requester', 'decider', 'run'])),
            'pdf_attached' => (bool) $pdfBytes,
        ]);
    }

    public function forRun($id)
    {
        if ($r = $this->unavailable()) {
            return $r;
        }

        $rows = PayrollApprovalRequest::with(['requester:id,name', 'decider:id,name', 'run'])
            ->where('payroll_run_id', $id)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn ($r) => $this->present($r));

        return response()->json([
            'supported' => true,
            'data'      => $rows,
            'approvers' => \App\Services\PayrollApprovals::approverEmails(),
        ]);
    }

    /** Waiting requests: an admin sees all of them, an executive only their own. */
    public function pending(Request $request)
    {
        if ($r = $this->unavailable()) {
            return $r;
        }

        $rows = PayrollApprovalRequest::with(['requester:id,name', 'decider:id,name', 'run'])
            ->where('status', PayrollApprovalRequest::PENDING)
            ->when(!$this->isAdmin(), fn ($q) => $q->where('requested_by', Auth::id()))
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->filter(fn ($r) => $r->run && !in_array($r->run->status, [PayrollRun::CANCELLED], true))
            ->map(fn ($r) => $this->present($r))
            ->values();

        return response()->json(['supported' => true, 'data' => $rows]);
    }

    public function pdf($reqId)
    {
        if ($r = $this->unavailable()) {
            return response()->json(['message' => 'Not available yet.'], 404);
        }

        $req = PayrollApprovalRequest::findOrFail($reqId);

        if (!$req->pdf_path || !Storage::disk('local')->exists($req->pdf_path)) {
            return response()->json(['message' => 'No PDF was attached to this request.'], 404);
        }

        return response(Storage::disk('local')->get($req->pdf_path), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Payroll-request-' . $req->id . '.pdf"',
            'Cache-Control'       => 'private, max-age=300',
        ]);
    }

    /** Admin only: approve, send back with changes, or reject. */
    public function decide(Request $request, $reqId)
    {
        if (!$this->isAdmin()) {
            return response()->json(['message' => 'Only an admin can decide on an approval request.'], 403);
        }
        if ($r = $this->unavailable()) {
            return response()->json($r->getData(true), 503);
        }

        $data = $request->validate([
            'decision' => 'required|in:approve,changes,reject',
            'note'     => 'nullable|string|max:1000',
        ]);

        if ($data['decision'] !== 'approve' && strlen(trim((string) ($data['note'] ?? ''))) < 3) {
            return response()->json(['message' => 'Please add a short note so the requester knows what to change.'], 422);
        }

        $req = PayrollApprovalRequest::with('requester')->findOrFail($reqId);
        $run = PayrollRun::findOrFail($req->payroll_run_id);

        if ($req->status !== PayrollApprovalRequest::PENDING) {
            return response()->json(['message' => 'This request has already been answered (' . $req->status . ').'], 422);
        }

        $admin = Auth::user();

        if ($data['decision'] === 'approve') {
            if (!in_array($run->status, [PayrollRun::DRAFT, PayrollRun::PENDING], true)) {
                return response()->json(['message' => 'This payroll is already ' . strtolower($run->status) . '.'], 422);
            }

            // approve() also marks this request Approved and tells the requester.
            $req->decision_note = $data['note'] ?? null;
            $req->save();
            PayrollWorkflow::approve($run, $admin->id);

            return response()->json(['message' => 'Payroll approved.', 'data' => $this->present($req->fresh(['requester', 'decider', 'run']))]);
        }

        $status = $data['decision'] === 'changes' ? PayrollApprovalRequest::CHANGES : PayrollApprovalRequest::REJECTED;

        $req->update([
            'status'        => $status,
            'decided_by'    => $admin->id,
            'decided_at'    => now(),
            'decision_note' => trim((string) $data['note']),
        ]);

        if ($run->status === PayrollRun::PENDING) {
            PayrollWorkflow::backToDraft($run, $admin->id, trim((string) $data['note']));
        }

        PayrollApprovals::notifyDecision($req->fresh('requester'), $run->fresh(), $admin, $data['decision'] === 'changes' ? 'changes' : 'rejected');

        return response()->json([
            'message' => $data['decision'] === 'changes' ? 'Sent back with your note.' : 'Request declined.',
            'data'    => $this->present($req->fresh(['requester', 'decider', 'run'])),
        ]);
    }
}
