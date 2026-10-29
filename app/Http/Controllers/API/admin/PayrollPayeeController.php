<?php

namespace App\Http\Controllers\API\admin;

use App\Http\Controllers\Controller;
use App\Models\PayrollPayee;
use App\Models\Payslip;
use App\Models\SalaryProfile;
use App\Services\PayrollAudit;
use App\Support\PayrollSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Service providers: outside people or companies (for example a firm that supports Archilance) that are paid through
 * payroll every month but never use the portal. Their salary, payslips and payments live in the normal salary /
 * payroll screens (addressed by the provider's "virtual user id", see PayrollPayee); this controller only manages the
 * provider record itself (name, company, category, contact, status).
 *
 * Nothing is ever hard-deleted once money has moved: a provider with payslips is set to Inactive instead, so payroll
 * history and reports stay intact.
 */
class PayrollPayeeController extends Controller
{
    public function categories(): array
    {
        return ['Service provider', 'IT & software', 'Support services', 'Consultant', 'Contractor', 'Other'];
    }

    private function unavailable()
    {
        return PayrollSchema::table('payroll_payees')
            ? null
            : response()->json(['supported' => false, 'message' => 'Service providers need the latest database update (System settings → Update database).', 'data' => []], 200);
    }

    private function present(PayrollPayee $p, ?SalaryProfile $profile = null): array
    {
        return [
            'id'          => $p->id,
            'user_id'     => $p->virtualId(),
            'name'        => $p->name,
            'company'     => $p->company,
            'category'    => $p->category,
            'email'       => $p->email,
            'phone'       => $p->phone,
            'country'     => $p->country,
            'status'      => $p->status,
            'notes'       => $p->notes,
            'monthly_salary' => $profile ? (float) $profile->monthly_salary : null,
            'currency'    => $profile->currency ?? 'PKR',
            'payout_route' => $profile->payout_route ?? 'payoneer',
        ];
    }

    public function index()
    {
        if ($r = $this->unavailable()) {
            return $r;
        }

        $payees = PayrollPayee::orderBy('name')->get();
        $profiles = SalaryProfile::whereIn('user_id', $payees->map(fn ($p) => $p->virtualId()))->get()->keyBy('user_id');

        return response()->json([
            'supported'  => true,
            'data'       => $payees->map(fn ($p) => $this->present($p, $profiles->get($p->virtualId())))->values(),
            'categories' => $this->categories(),
        ]);
    }

    private function rules(): array
    {
        return [
            'name'     => 'required|string|max:150',
            'company'  => 'nullable|string|max:150',
            'category' => 'nullable|string|max:80',
            'email'    => 'nullable|email|max:190',
            'phone'    => 'nullable|string|max:60',
            'country'  => 'nullable|string|max:80',
            'status'   => 'nullable|in:Active,Inactive',
            'notes'    => 'nullable|string|max:2000',
        ];
    }

    public function store(Request $request)
    {
        if ($r = $this->unavailable()) {
            return response()->json($r->getData(true), 503);
        }

        $data = $request->validate($this->rules());
        $data['name'] = trim($data['name']);

        $dupe = PayrollPayee::whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])->exists();
        if ($dupe) {
            return response()->json(['message' => 'A service provider with this name already exists.'], 422);
        }

        $payee = PayrollPayee::create([
            'name'       => $data['name'],
            'company'    => $data['company'] ?? null,
            'category'   => ($data['category'] ?? null) ?: 'Service provider',
            'email'      => $data['email'] ?? null,
            'phone'      => $data['phone'] ?? null,
            'country'    => $data['country'] ?? null,
            'status'     => $data['status'] ?? 'Active',
            'notes'      => $data['notes'] ?? null,
            'created_by' => Auth::id(),
        ]);

        PayrollAudit::log('provider.created', null, null, $payee->virtualId(), null, $payee->name);

        return response()->json(['message' => 'Service provider added.', 'data' => $this->present($payee)], 201);
    }

    public function update(Request $request, $id)
    {
        if ($r = $this->unavailable()) {
            return response()->json($r->getData(true), 503);
        }

        $payee = PayrollPayee::findOrFail($id);
        $data = $request->validate($this->rules());
        $name = trim($data['name']);

        if (PayrollPayee::where('id', '!=', $payee->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            return response()->json(['message' => 'Another service provider already has this name.'], 422);
        }

        $payee->update([
            'name'     => $name,
            'company'  => $data['company'] ?? null,
            'category' => ($data['category'] ?? null) ?: 'Service provider',
            'email'    => $data['email'] ?? null,
            'phone'    => $data['phone'] ?? null,
            'country'  => $data['country'] ?? null,
            'status'   => $data['status'] ?? $payee->status,
            'notes'    => $data['notes'] ?? null,
        ]);

        // Keep an unpaid provider's salary profile in step with the record being switched off / on.
        $profile = SalaryProfile::where('user_id', $payee->virtualId())->first();
        if ($profile && isset($data['status'])) {
            $profile->status = $data['status'] === 'Inactive' ? 'Inactive' : ($profile->status === 'Inactive' ? 'Active' : $profile->status);
            $profile->save();
        }

        return response()->json(['message' => 'Saved.', 'data' => $this->present($payee->fresh(), $profile)]);
    }

    /** Remove a provider: deleted if it was never paid, otherwise switched off so history is kept. */
    public function destroy($id)
    {
        if ($r = $this->unavailable()) {
            return response()->json($r->getData(true), 503);
        }

        $payee = PayrollPayee::findOrFail($id);
        $hasSlips = Payslip::where('user_id', $payee->virtualId())->exists();

        if ($hasSlips) {
            $payee->update(['status' => 'Inactive']);
            SalaryProfile::where('user_id', $payee->virtualId())->update(['status' => 'Inactive']);

            return response()->json(['message' => 'This provider has payroll history, so it was switched off instead of deleted.', 'deactivated' => true]);
        }

        SalaryProfile::where('user_id', $payee->virtualId())->delete();
        $payee->delete();

        return response()->json(['message' => 'Service provider removed.', 'deactivated' => false]);
    }
}
