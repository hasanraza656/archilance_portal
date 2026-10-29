<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Validation\Rule;
use Auth;

class UserManagementController extends Controller
{
    /**
     * Accepts 'male' / 'female' in any casing, anything else becomes null
     * ("not recorded"). Kept permissive rather than a validation rule so an
     * unexpected value can never make an otherwise-valid user save fail —
     * LeavePolicy already treats null as "show both parental categories".
     */
    private function normalizeGender($value): ?string
    {
        $gender = strtolower(trim((string) $value));

        return in_array($gender, ['male', 'female'], true) ? $gender : null;
    }

    // Helper to detect role from route
    private function getRoleFromRequest(Request $request)
    {
        $path = $request->path(); // e.g., 'api/employee/employee-user/5'
        $parts = explode('/', $path); // ['api', 'employee', 'employee-user', '5']

        // Ensure the path is long enough
        if (count($parts) < 3) {
            abort(404, 'Invalid URL format');
        }

        // The role is typically in the second segment after 'api'
        $roleKey = $parts[1]; // 'admin', 'employee', etc.
        $segment = $parts[2]; // 'employee-user', etc.

        return match ($segment) {
            'employee-user' => 3,
            'admin-user' => 2,
            'customer-user' => 4,
            default => abort(404, 'Invalid user type'),
        };
    }


    /**
     * Optional ?employee_type= filter. Opt-in: when the parameter is absent the query is
     * left untouched, so existing clients behave exactly as before.
     *
     * Accepts:
     *   ?employee_type=Manager                 single type, matched literally
     *   ?employee_type=Employee                also literal — plain employees are stored as
     *                                          the string 'Employee' in some rows
     *   ?employee_type=Manager,Executive       comma-separated list
     *   ?employee_type=none                    rows where the column is NULL or '' (store()
     *                                          writes '' when no type is supplied)
     *   ?employee_type=Employee,none           literal 'Employee' OR no-type-set — use this
     *                                          when the data contains a mix of both
     *
     * The whole condition is wrapped in one group so it ANDs cleanly with any existing
     * OR-based visibility rules on the query.
     */
    private function applyEmployeeTypeFilter($query, Request $request)
    {
        if (!$request->filled('employee_type')) {
            return $query;
        }

        $requested = collect(explode(',', (string) $request->input('employee_type')))
            ->map(fn($t) => trim($t))
            ->filter()
            ->values();

        $isNoneToken = fn($t) => in_array(strtolower($t), ['none', 'null'], true);

        $wantsNone = $requested->contains($isNoneToken);
        $named = $requested->reject($isNoneToken)->values()->all();

        // Nothing usable was supplied (e.g. "?employee_type=,,") — leave the query alone.
        if (empty($named) && !$wantsNone) {
            return $query;
        }

        return $query->where(function ($q) use ($named, $wantsNone) {
            if (!empty($named)) {
                $q->whereIn('employee_type', $named);
            }

            if ($wantsNone) {
                if (empty($named)) {
                    $q->whereNull('employee_type')->orWhere('employee_type', '');
                } else {
                    $q->orWhereNull('employee_type')->orWhere('employee_type', '');
                }
            }
        });
    }


    /**
     * Optional ?employee_team= filter. Opt-in: when the parameter is absent the query is untouched,
     * so older clients are unaffected.
     *
     *   ?employee_team=3D Team           single team, matched literally
     *   ?employee_team=BIM Team,3D Team  comma-separated list
     *   ?employee_team=none              rows with no team set (NULL or '')
     *   ?employee_team=3D Team,none      a team OR no team
     *
     * Applied with its own group, so it can only narrow what the visibility rules already allow.
     */
    private function applyEmployeeTeamFilter($query, Request $request): void
    {
        if (!$request->filled('employee_team')) {
            return;
        }

        $requested = collect(explode(',', (string) $request->input('employee_team')))
            ->map(fn ($t) => trim($t))
            ->filter(fn ($t) => $t !== '')
            ->unique()
            ->values();

        if ($requested->isEmpty()) {
            return;
        }

        $named = $requested->reject(fn ($t) => strtolower($t) === 'none')->values();
        $wantsNone = $requested->contains(fn ($t) => strtolower($t) === 'none');

        $query->where(function ($q) use ($named, $wantsNone) {
            if ($named->isNotEmpty()) {
                $q->whereIn('employee_team', $named->all());
            }
            if ($wantsNone) {
                $none = fn ($x) => $x->whereNull('employee_team')->orWhere('employee_team', '');
                $named->isNotEmpty() ? $q->orWhere($none) : $q->where($none);
            }
        });
    }


    /**
     * Optional "hire end date" (last day / contract end). Opt-in like employee_team and gender: written only when the
     * request actually carries the key AND the column exists, so an older client can never blank it on an ordinary
     * edit, and uploading this code before the database update (System settings -> Update database) changes nothing.
     */
    private function hireEndDateData(Request $request): array
    {
        if (!$request->has('hire_end_date') || !\Illuminate\Support\Facades\Schema::hasColumn('users', 'hire_end_date')) {
            return [];
        }

        $value = $request->input('hire_end_date');

        return ['hire_end_date' => ($value === null || $value === '') ? null : \Carbon\Carbon::parse($value)->toDateString()];
    }

    /** 422 when the hire end date is not a date, or falls before the joining date. Null when fine / not sent. */
    private function hireEndDateProblem(Request $request, $joiningFallback = null)
    {
        if (!$request->filled('hire_end_date')) {
            return null;
        }

        try {
            $end = \Carbon\Carbon::parse($request->input('hire_end_date'))->startOfDay();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Hire end date is not a valid date.', 'errors' => ['hire_end_date' => ['Hire end date is not a valid date.']]], 422);
        }

        $joining = $request->filled('joining_date') ? $request->input('joining_date') : $joiningFallback;
        if ($joining) {
            try {
                if ($end->lt(\Carbon\Carbon::parse($joining)->startOfDay())) {
                    return response()->json(['message' => 'Hire end date cannot be before the joining date.', 'errors' => ['hire_end_date' => ['Hire end date cannot be before the joining date.']]], 422);
                }
            } catch (\Throwable $e) {
                // an unreadable joining date is not this field's problem
            }
        }

        return null;
    }

    public function index(Request $request)
    {
        $roleId = $this->getRoleFromRequest($request);
        $user = Auth::user();

        // NOTE: the visibility rules below are wrapped in ONE outer group. Without that
        // wrapper, appending any further ->where() (e.g. the employee_type filter) would bind
        // tighter than the orWhere and produce
        //     (role AND type != 'Manager') OR (id = X AND filter)
        // which would let the "exclude Managers" restriction leak. Wrapped, we always get
        //     ((role AND type != 'Manager') OR id = X) AND filter
        // With no filter applied the wrapper is logically identical to the previous query.
        if ($user->employee_type !== "Executive") {
            // Non-Executives: see role users but exclude Managers
            $query = User::where(function ($outer) use ($roleId, $user) {
                $outer->where(function ($query) use ($roleId) {
                    $query->where('user_role', $roleId)
                        ->where('employee_type', '!=', 'Manager');
                })
                    ->orWhere('id', $user->id); // include logged-in user
            });
        } else {
            // Executives: see role users including Managers
            $query = User::where(function ($outer) use ($roleId, $user) {
                $outer->where('user_role', $roleId)
                    ->orWhere('id', $user->id); // include logged-in user
            });
        }

        // Optional employee_type filter — applied ON TOP of the visibility rules above, so a
        // non-Executive asking for ?employee_type=Manager correctly gets an empty list rather
        // than bypassing the restriction.
        $this->applyEmployeeTypeFilter($query, $request);

        // Optional ?employee_team= filter (no-op when the param is absent).
        $this->applyEmployeeTeamFilter($query, $request);

        // Optional server-side search across the common identity fields. Opt-in: when the
        // `search` param is absent the query is untouched, so a backend-only deploy leaves
        // the current frontend working exactly as before. ANDed after the visibility/type
        // filters above, so it can only narrow what the user is already allowed to see.
        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            if ($term !== '') {
                $like = '%' . $term . '%';
                $query->where(function ($q) use ($like) {
                    $q->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('username', 'like', $like)
                        ->orWhere('phone', 'like', $like);
                });
            }
        }

        // OPT-IN pagination: only when the client actually asks for it (page / per_page).
        // Clients that send neither keep receiving the full plain array exactly as before,
        // so the existing frontend is unaffected by a backend-only deploy.
        $wantsPagination = $request->filled('page') || $request->filled('per_page');
        $paginator = null;

        if ($wantsPagination) {
            $perPage = (int) $request->input('per_page', 25);
            $perPage = max(1, min($perPage, 200));
            $paginator = $query->paginate($perPage);
            $users = $paginator->getCollection();
        } else {
            $users = $query->get();
        }

        // today_time / week_time are only needed on the employee tracking list.
        // Filled for the whole page in 2 queries instead of the accessors' per-user,
        // per-session queries (the N+1 that made this list slow).
        if ($roleId === 3) {
            User::preloadWorkedTimes($users);
            $users->each->append(['today_time', 'week_time']);
        }

        if ($wantsPagination) {
            // Same standard Laravel envelope ({ data, current_page, last_page, total, ... }).
            $paginator->setCollection($users);
            return response()->json($paginator);
        }

        return response()->json($users);
    }

    public function store(Request $request)
    {

        $user = Auth::user();

        if ($user->employee_type != "Manager" && $user->employee_type != "Supervisor" && $user->employee_type != "Executive") {

            return response()->json(['message' => 'Unauthorized'], 403);

        }

        $roleId = $this->getRoleFromRequest($request);


        $existing = User::withTrashed()->where('email', $request->email)->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
                $existing->update([
                    'name' => $request->name,
                    'username' => $request->username,
                    'phone' => $request->phone,
                    'password' => bcrypt($request->password),
                    'user_role' => $roleId,
                    'employee_type' => $request->employee_type ?? '',
                    
                ]);
                return response()->json($existing, 200);
            } else {
                return response()->json(['error' => 'Email already exists.'], 422);
            }
        }


        $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'nullable',
                'email',
                Rule::unique('users')->whereNull('deleted_at'),
            ],
            'username' => [
                'nullable',
                'string',
                Rule::unique('users')->whereNull('deleted_at'),
            ],
            'phone' => [
                'nullable',
                'string',
                Rule::unique('users')->whereNull('deleted_at'),
            ],
            'password' => 'required|string|min:6',
        ]);

        if ($problem = $this->hireEndDateProblem($request)) {
            return $problem;
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'username' => $request->username,
            'phone' => $request->phone,
            'password' => bcrypt($request->password),
            'user_role' => $roleId,
            'employee_type' => $request->employee_type ?? '',
            'employee_team' => $request->input('employee_team') ?: null,
            // Drives Maternity / Paternity eligibility (LeavePolicy). Optional —
            // null means "not recorded", which the policy treats permissively.
            'gender' => $this->normalizeGender($request->input('gender')),
            'internee_manager_id' => $request->internee_manager_id ?? null,
            'manager_id' => $request->manager_id ?? null,
            'probation_period_end_date' => $request->probation_period_end_date,
            'subscription_from' => $request->subscription_from ?? null,
            // 1 = "contract already accepted" (login allowed immediately); absent -> 0
            // (gated, must accept the contract before logging in).
            'contract_status' => (int) $request->boolean('contract_status'),
        ] + $this->hireEndDateData($request));

        return response()->json($user, 201);
    }

    public function show(Request $request, $id)
    {
        $roleId = $this->getRoleFromRequest($request);
        $user = User::where('user_role', $roleId)->findOrFail($id);
        return response()->json($user);
    }

    public function update(Request $request, $id)
    {

        $user = Auth::user();

        if ($user->employee_type != "Manager" && $user->employee_type != "Supervisor" && $user->employee_type != "Executive") {

            return response()->json(['message' => 'Unauthorized'], 403);

        }

        $roleId = $this->getRoleFromRequest($request);
        $user = User::where('user_role', $roleId)->findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'nullable',
                'email',
                Rule::unique('users')->ignore($id)->whereNull('deleted_at'),
            ],
            'username' => [
                'nullable',
                'string',
                Rule::unique('users')->ignore($id)->whereNull('deleted_at'),
            ],
            'phone' => [
                'nullable',
                'string',
                Rule::unique('users')->ignore($id)->whereNull('deleted_at'),
            ],
            'password' => 'nullable|min:8|confirmed', // ✅ uses password_confirmation automatically
        ]);

        $updateData = [
            'name' => $request->name,
            'email' => $request->email,
            'username' => $request->username,
            'phone' => $request->phone,
            'employee_type' => $request->employee_type,
            'internee_manager_id' => $request->internee_manager_id,
            'manager_id' => $request->manager_id,
            'probation_period_end_date' => $request->probation_period_end_date,
            'subscription_from' => $request->subscription_from ?? null
        ];

        // employee_team only when the form actually sends it — clients that
        // predate the field must never wipe a saved team on an ordinary edit.
        if ($request->has('employee_team')) {
            $updateData['employee_team'] = $request->input('employee_team') ?: null;
        }

        // Same opt-in treatment for gender: an older portal that knows nothing
        // about the field must not blank it out every time somebody edits a user.
        if ($request->has('gender')) {
            $updateData['gender'] = $this->normalizeGender($request->input('gender'));
        }

        // Only touch contract_status when the form actually sends it — a normal edit
        // must never silently re-lock (or unlock) a user.
        if ($request->has('contract_status')) {
            $updateData['contract_status'] = (int) $request->boolean('contract_status');
        }

        // Update password only if provided
        if ($request->filled('password')) {
            $updateData['password'] = bcrypt($request->password);
        }

        if ($problem = $this->hireEndDateProblem($request, $user->joining_date)) {
            return $problem;
        }

        $updateData += $this->hireEndDateData($request);

        $user->update($updateData);

        return response()->json($user);
    }

    public function destroy(Request $request, $id)
    {

        $user = Auth::user();

        if ($user->employee_type != "Manager" && $user->employee_type != "Supervisor" && $user->employee_type != "Executive") {

            return response()->json(['message' => 'Unauthorized'], 403);

        }

        $roleId = $this->getRoleFromRequest($request);
        $user = User::where('user_role', $roleId)->findOrFail($id);
        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }
}

