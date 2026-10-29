<?php

namespace App\Http\Controllers\API\admin;

use App\Http\Controllers\Controller;
use App\Models\PayrollPayee;
use App\Models\Payslip;
use App\Models\SalaryProfile;
use App\Models\SalaryRevision;
use App\Models\User;
use App\Support\PayrollAccess;
use App\Support\PayrollSchema;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Salaries: who earns what, the history of how it changed, and each person's
 * payment record.
 *
 * ── SCOPE ───────────────────────────────────────────────────────────────────
 * The roster is the employee roster (users.user_role = 3, not soft-deleted) plus the active service providers
 * (outside people / companies paid through payroll without a portal account). Customers, customer team members and
 * admins can never appear, because they are never selected.
 *
 * ── WHO SEES WHOSE SALARY ───────────────────────────────────────────────────
 * See PayrollAccess: executives' salaries are visible only to admins and to the people listed in
 * PAYROLL_FULL_VIEW_USER_IDS (the accountant). Everyone else gets the roster without them, and a direct request for
 * one of them is refused.
 *
 * ── CURRENCY ────────────────────────────────────────────────────────────────
 * Each salary profile has its own currency (PKR by default, USD for people paid in dollars). Money is never added
 * across currencies: totals are returned per currency.
 */
class SalaryController extends Controller
{
    /** Employee + service-provider roster, salary and payment summary, in a fixed number of queries. */
    public function index(Request $request)
    {
        $monthStart = $this->monthStart($request->input('month'));
        $hidden = PayrollAccess::hiddenUserIds();

        // People whose account was deleted during (or after) the chosen month stay on that month's list: they are paid until their last day.
        $users = User::withTrashed()->where('user_role', 3)
            ->where(function ($q) use ($monthStart) {
                $q->whereNull('deleted_at')->orWhere('deleted_at', '>=', $monthStart);
            })
            ->when($hidden, fn ($q) => $q->whereNotIn('id', $hidden))
            ->when($request->filled('search'), function ($q) use ($request) {
                $like = '%' . trim((string) $request->input('search')) . '%';
                $q->where(function ($w) use ($like) {
                    $w->where('name', 'like', $like)->orWhere('email', 'like', $like);
                });
            })
            ->when($request->filled('employee_team'), fn ($q) => $q->where('employee_team', $request->input('employee_team')))
            ->when($request->filled('employee_type'), fn ($q) => $q->where('employee_type', $request->input('employee_type')))
            ->orderBy('name')
            ->get(array_merge(['id', 'name', 'email', 'profile_pic', 'employee_type', 'employee_team', 'joining_date', 'contract_status', 'deleted_at'], PayrollSchema::has('users', 'hire_end_date') ? ['hire_end_date'] : []));

        // Last day each person is paid for (offboarding record, else the deleted account's end date). Anyone whose last day
        // was before the chosen month is not part of it; a future last day shows as "leaving".
        $generator = app(\App\Services\PayrollGenerator::class);
        $lastDays = [];
        foreach ($users as $u) {
            $lastDays[$u->id] = $generator->lastDay($u);
        }
        $users = $users->filter(fn ($u) => !($lastDays[$u->id] && $lastDays[$u->id]->lt($monthStart)))->values();

        // Service providers: only when the table exists, and never when the list is narrowed to a staff team / type.
        $payees = collect();
        if (PayrollSchema::table('payroll_payees') && !$request->filled('employee_team') && !$request->filled('employee_type')) {
            $payees = PayrollPayee::query()
                ->when($request->filled('search'), function ($q) use ($request) {
                    $like = '%' . trim((string) $request->input('search')) . '%';
                    $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('company', 'like', $like)->orWhere('email', 'like', $like));
                })
                ->orderBy('name')
                ->get();
        }

        $ids = $users->pluck('id')->merge($payees->map(fn ($p) => $p->virtualId()))->all();

        $profiles = SalaryProfile::whereIn('user_id', $ids)->get()->keyBy('user_id');

        // Lifetime paid + last payment date, one grouped query rather than per-user.
        $paidTotals = Payslip::whereIn('user_id', $ids)
            ->where('status', 'Paid')
            ->selectRaw('user_id, SUM(net_amount) AS total_paid, MAX(paid_at) AS last_paid_at, COUNT(*) AS payments')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        // This month's slip, if payroll has been generated for it.
        $thisMonth = Payslip::whereIn('user_id', $ids)
            ->whereDate('period_month', $monthStart)
            ->get(['id', 'user_id', 'status', 'net_amount', 'payroll_run_id', 'paid_at'])
            ->keyBy('user_id');

        $row = function ($id, array $who) use ($profiles, $paidTotals, $thisMonth) {
            $profile = $profiles->get($id);
            $paid    = $paidTotals->get($id);
            $slip    = $thisMonth->get($id);

            return $who + [
                'user_id'          => $id,
                'monthly_salary'   => $profile ? (float) $profile->monthly_salary : null,
                'currency'         => $profile->currency ?? 'PKR',
                'salary_status'    => $profile->status ?? null,
                'effective_from'   => optional($profile)->effective_from,
                'payment_method'   => $profile->payment_method ?? null,
                'payment_account'  => $profile->payment_reference ?? null,
                'payout_route'     => $profile->payout_route ?? 'payoneer',
                'has_salary'       => (bool) ($profile && (float) $profile->monthly_salary > 0),

                'total_paid'       => $paid ? (float) $paid->total_paid : 0.0,
                'payments_count'   => $paid ? (int) $paid->payments : 0,
                'last_paid_at'     => $paid->last_paid_at ?? null,

                // "Not Generated" is a real, meaningful state here — it is the
                // difference between "we haven't run payroll" and "we ran it and
                // haven't paid them", which the finance team needs to tell apart.
                'current_month_status' => $slip->status ?? 'Not Generated',
                'current_month_net'    => $slip ? (float) $slip->net_amount : null,
                'current_payslip_id'   => $slip->id ?? null,
            ];
        };

        $staffRows = $users->map(fn ($user) => $row($user->id, [
            'kind'             => 'employee',
            'name'             => $user->name,
            'email'            => $user->email,
            'profile_pic'      => $user->profile_pic,
            'employee_type'    => $user->employee_type,
            'employee_team'    => $user->employee_team,
            'joining_date'     => $user->joining_date,
            'contract_status'  => (int) ($user->contract_status ?? 1),
            'left_on'          => ($lastDays[$user->id] ?? null) ? $lastDays[$user->id]->toDateString() : null,
            'leaving'          => ($lastDays[$user->id] ?? null) ? $lastDays[$user->id]->gte(Carbon::today()) : false,
        ]));

        $providerRows = $payees->map(fn ($p) => $row($p->virtualId(), [
            'kind'             => 'provider',
            'payee_id'         => (int) $p->id,
            'name'             => $p->name,
            'company'          => $p->company,
            'email'            => $p->email,
            'phone'            => $p->phone,
            'country'          => $p->country,
            'profile_pic'      => null,
            'employee_type'    => 'Service provider',
            'employee_team'    => $p->category ?: 'Service providers',
            'category'         => $p->category,
            'provider_status'  => $p->status,
            'notes'            => $p->notes,
            'joining_date'     => null,
            'contract_status'  => 1,
        ]));

        $rows = $staffRows->concat($providerRows)->values();

        // Money is added per currency, never across currencies.
        $active = $rows->where('salary_status', 'Active');
        $monthlyTotals = $active->groupBy('currency')
            ->map(fn ($g, $cur) => ['currency' => $cur, 'total' => round((float) $g->sum('monthly_salary'), 2), 'people' => $g->count()])
            ->values();
        $pkr = $monthlyTotals->firstWhere('currency', 'PKR');

        return response()->json([
            'data'       => $rows,
            'month'      => $monthStart->toDateString(),
            'restricted' => PayrollAccess::restricted(),
            'summary'    => [
                'employees'        => $staffRows->count(),
                'providers'        => $providerRows->count(),
                'with_salary'      => $rows->where('has_salary', true)->count(),
                'missing_salary'   => $rows->where('has_salary', false)->count(),
                'monthly_total'    => $pkr['total'] ?? 0,
                'monthly_totals'   => $monthlyTotals,
                'paid_this_month'  => $rows->where('current_month_status', 'Paid')->count(),
                'lifetime_paid'    => round($rows->where('currency', 'PKR')->sum('total_paid'), 2),
                'local_route'      => $active->where('payout_route', 'local')->count(),
            ],
        ]);
    }

    /** One person: current arrangement, full revision history, payment history. */
    public function show($userId)
    {
        PayrollAccess::guard($userId);

        if (PayrollPayee::isVirtual($userId)) {
            $payee = $this->payeeOrFail($userId);
            $who = [
                'id'            => $payee->virtualId(),
                'kind'          => 'provider',
                'payee_id'      => (int) $payee->id,
                'name'          => $payee->name,
                'company'       => $payee->company,
                'email'         => $payee->email,
                'phone'         => $payee->phone,
                'country'       => $payee->country,
                'profile_pic'   => null,
                'employee_type' => 'Service provider',
                'employee_team' => $payee->category,
                'joining_date'  => null,
                'status'        => $payee->status,
                'notes'         => $payee->notes,
            ];
            $targetId = $payee->virtualId();
        } else {
            $user = User::withTrashed()->where('user_role', 3)->findOrFail($userId);
            $who = [
                'id'            => $user->id,
                'kind'          => 'employee',
                'name'          => $user->name,
                'email'         => $user->email,
                'profile_pic'   => $user->profile_pic,
                'employee_type' => $user->employee_type,
                'employee_team' => $user->employee_team,
                'joining_date'  => $user->joining_date,
            ];
            $targetId = $user->id;
        }

        $profile   = SalaryProfile::where('user_id', $targetId)->first();
        $revisions = SalaryRevision::with('creator:id,name')
            ->where('user_id', $targetId)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        $payslips = Payslip::with('items')
            ->where('user_id', $targetId)
            ->orderByDesc('period_month')
            ->get();

        return response()->json([
            'user'      => $who,
            'profile'   => $profile,
            'revisions' => $revisions,
            'payslips'  => $payslips,
            'totals'    => [
                'lifetime_paid' => round((float) $payslips->where('status', 'Paid')->sum('net_amount'), 2),
                'payments'      => $payslips->where('status', 'Paid')->count(),
                'last_paid_at'  => $payslips->where('status', 'Paid')->max('paid_at'),
            ],
        ]);
    }

    /**
     * Set or adjust someone's salary.
     *
     * Profile update and history entry are written in ONE transaction: a salary
     * that changed without leaving a revision behind would silently break the
     * audit trail this module exists to provide.
     *
     * Already-issued payslips are deliberately NOT touched — they carry their own
     * copy of the figure. A raise applies to payroll generated from here on.
     */
    public function setSalary(Request $request, $userId)
    {
        PayrollAccess::guard($userId);

        if (PayrollPayee::isVirtual($userId)) {
            $target = $this->payeeOrFail($userId)->virtualId();
        } else {
            $target = User::where('user_role', 3)->findOrFail($userId)->id;
        }

        $data = $request->validate([
            'monthly_salary'    => 'required|numeric|min:0|max:999999999',
            'effective_from'    => 'nullable|date',
            'reason'            => 'nullable|string|max:1000',
            'currency'          => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'status'            => 'nullable|in:Active,On Hold,Inactive',
            'payment_method'    => 'nullable|string|max:120',
            'payment_reference' => 'nullable|string|max:190',
            'payout_route'      => 'nullable|in:payoneer,local',
            'notes'             => 'nullable|string|max:2000',
        ]);
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }

        $effectiveFrom = isset($data['effective_from'])
            ? Carbon::parse($data['effective_from'])->startOfDay()
            : Carbon::today();

        $newAmount = round((float) $data['monthly_salary'], 2);

        $profile = DB::transaction(function () use ($target, $data, $newAmount, $effectiveFrom) {
            $profile  = SalaryProfile::where('user_id', $target)->first();
            $previous = $profile ? (float) $profile->monthly_salary : null;

            if (!$profile) {
                $profile = new SalaryProfile([
                    'user_id'    => $target,
                    'created_by' => Auth::id(),
                ]);
            }

            $profile->monthly_salary = $newAmount;
            $profile->currency       = $data['currency'] ?? $profile->currency ?? 'PKR';
            $profile->status         = $data['status'] ?? $profile->status ?? 'Active';
            $profile->effective_from = $effectiveFrom;
            $profile->updated_by     = Auth::id();
            foreach (['payment_method', 'payment_reference', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $profile->{$field} = $data[$field];
                }
            }
            // The payout route only exists once the database update has been applied; before that it is ignored.
            if (array_key_exists('payout_route', $data) && PayrollSchema::has('salary_profiles', 'payout_route')) {
                $profile->payout_route = $data['payout_route'];
            }
            $profile->save();

            // Only record a revision when the figure actually moved — re-saving
            // payment details shouldn't litter the history with no-op entries.
            if ($previous === null || abs($previous - $newAmount) > 0.001) {
                SalaryRevision::create([
                    'user_id'         => $target,
                    'previous_amount' => $previous,
                    'new_amount'      => $newAmount,
                    'change_amount'   => $previous === null ? 0 : round($newAmount - $previous, 2),
                    'type'            => $this->revisionType($previous, $newAmount),
                    'effective_from'  => $effectiveFrom,
                    'reason'          => $data['reason'] ?? null,
                    'created_by'      => Auth::id(),
                ]);
            }

            return $profile;
        });

        return response()->json([
            'message' => 'Salary updated.',
            'data'    => $profile->fresh(),
        ]);
    }

    private function payeeOrFail($virtualId): PayrollPayee
    {
        if (!PayrollSchema::table('payroll_payees')) {
            abort(404, 'Service providers are not set up yet.');
        }

        return PayrollPayee::findOrFail(PayrollPayee::idFromVirtual($virtualId));
    }

    private function revisionType(?float $previous, float $next): string
    {
        if ($previous === null) return 'Initial';
        if ($next > $previous)  return 'Increment';
        if ($next < $previous)  return 'Decrement';
        return 'Correction';
    }

    /** Normalises any date (or nothing) to the first day of that month. */
    private function monthStart($value): Carbon
    {
        try {
            return $value ? Carbon::parse($value)->startOfMonth() : Carbon::today()->startOfMonth();
        } catch (\Throwable $e) {
            return Carbon::today()->startOfMonth();
        }
    }
}
