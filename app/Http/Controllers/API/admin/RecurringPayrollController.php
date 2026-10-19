<?php

namespace App\Http\Controllers\API\admin;

use App\Http\Controllers\Controller;
use App\Models\RecurringPayrollItem;
use App\Models\User;
use App\Services\PayrollAudit;
use App\Support\PayrollSchema;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Standing monthly items for one employee (allowance, loan instalment, tax
 * withholding…). They are added to every payroll generated while active, so the
 * accountant does not retype the same line each month.
 *
 * Until the payroll_advanced database update is applied (System settings →
 * Update database) these endpoints answer "not available yet" instead of failing.
 */
class RecurringPayrollController extends Controller
{
    private function unavailable()
    {
        return PayrollSchema::table('recurring_payroll_items')
            ? null
            : response()->json(['supported' => false, 'message' => 'Standing items need the latest database update (System settings → Update database).', 'data' => []], 200);
    }

    public function index($userId)
    {
        if ($r = $this->unavailable()) {
            return $r;
        }

        User::where('user_role', 3)->findOrFail($userId);

        $rows = RecurringPayrollItem::where('user_id', $userId)
            ->orderByDesc('is_active')
            ->orderBy('kind')
            ->orderBy('start_month')
            ->get();

        return response()->json(['supported' => true, 'data' => $rows]);
    }

    public function store(Request $request, $userId)
    {
        if (!PayrollSchema::table('recurring_payroll_items')) {
            return response()->json(['message' => 'Run the latest database update first (System settings).'], 503);
        }

        User::where('user_role', 3)->findOrFail($userId);

        $data = $request->validate([
            'kind'        => 'required|in:earning,deduction',
            'category'    => 'required|string|max:80',
            'label'       => 'nullable|string|max:190',
            'amount'      => 'required|numeric|min:0.01|max:999999999',
            'start_month' => 'required|date',
            'end_month'   => 'nullable|date|after_or_equal:start_month',
            'notes'       => 'nullable|string|max:1000',
        ]);

        $item = RecurringPayrollItem::create([
            'user_id'     => $userId,
            'kind'        => $data['kind'],
            'category'    => $data['category'],
            'label'       => $data['label'] ?? null,
            'amount'      => round((float) $data['amount'], 2),
            'start_month' => Carbon::parse($data['start_month'])->startOfMonth(),
            'end_month'   => !empty($data['end_month']) ? Carbon::parse($data['end_month'])->startOfMonth() : null,
            'is_active'   => true,
            'notes'       => $data['notes'] ?? null,
            'created_by'  => Auth::id(),
        ]);

        PayrollAudit::log('recurring.added', null, null, (int) $userId, null, $data['kind'] . ': ' . $data['category'], (float) $item->amount);

        return response()->json(['message' => 'Standing item added. It applies to payroll generated from ' . Carbon::parse($data['start_month'])->format('F Y') . '.', 'data' => $item]);
    }

    /** Stop / resume an item, or change its amount or end month. */
    public function update(Request $request, $userId, $itemId)
    {
        if (!PayrollSchema::table('recurring_payroll_items')) {
            return response()->json(['message' => 'Run the latest database update first (System settings).'], 503);
        }

        $item = RecurringPayrollItem::where('user_id', $userId)->findOrFail($itemId);

        $data = $request->validate([
            'is_active' => 'nullable|boolean',
            'amount'    => 'nullable|numeric|min:0.01|max:999999999',
            'end_month' => 'nullable|date',
            'label'     => 'nullable|string|max:190',
        ]);

        if (array_key_exists('is_active', $data) && $data['is_active'] !== null) {
            $item->is_active = (bool) $data['is_active'];
        }
        if (isset($data['amount'])) {
            $item->amount = round((float) $data['amount'], 2);
        }
        if ($request->has('end_month')) {
            $item->end_month = !empty($data['end_month']) ? Carbon::parse($data['end_month'])->startOfMonth() : null;
        }
        if (array_key_exists('label', $data)) {
            $item->label = $data['label'];
        }
        $item->save();

        PayrollAudit::log('recurring.updated', null, null, (int) $userId, null, $item->is_active ? 'active' : 'stopped', (float) $item->amount, $item->category);

        return response()->json(['message' => 'Updated.', 'data' => $item]);
    }

    public function destroy($userId, $itemId)
    {
        if (!PayrollSchema::table('recurring_payroll_items')) {
            return response()->json(['message' => 'Run the latest database update first (System settings).'], 503);
        }

        $item = RecurringPayrollItem::where('user_id', $userId)->findOrFail($itemId);
        PayrollAudit::log('recurring.removed', null, null, (int) $userId, $item->kind . ': ' . $item->category, null, (float) $item->amount);
        $item->delete(); // payslips already generated keep their own copy of the line

        return response()->json(['message' => 'Removed. Payslips already generated are not changed.']);
    }
}
