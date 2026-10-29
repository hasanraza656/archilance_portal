<?php

namespace App\Http\Controllers\API\admin;

use App\Http\Controllers\Controller;
use App\Models\Offboarding;
use App\Models\User;
use App\Services\OffboardingService;
use App\Support\NoticePolicy;
use App\Support\PayrollAccess;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Offboarding screen API. Reachable by admins (/api/admin/*) and executives (/api/employee/* behind employeeType:Executive).
 *
 * Who can be offboarded: employees (role 3). Nobody can offboard themselves, and only an admin can offboard an executive.
 * Nothing is deleted for good: the account is switched off (soft delete) on the chosen day and can be reinstated.
 */
class OffboardingController extends Controller
{
    private function svc(): OffboardingService
    {
        return app(OffboardingService::class);
    }

    private function unavailable()
    {
        return Offboarding::ready()
            ? null
            : response()->json(['supported' => false, 'message' => 'Offboarding needs the latest database update (System settings → Update database).', 'data' => [], 'counts' => []], 200);
    }

    private function actorIsAdmin(): bool
    {
        return (int) (Auth::user()->user_role ?? 0) === 2;
    }

    /** Null when the signed-in person may offboard this user, else the reason (a message). */
    private function refusal(User $target): ?string
    {
        if ((int) $target->id === (int) Auth::id()) {
            return 'You cannot offboard yourself.';
        }
        if ((int) $target->user_role !== 3) {
            return 'Only employees can be offboarded.';
        }
        if (!$this->actorIsAdmin() && $target->employee_type === 'Executive') {
            return 'Only an admin can offboard an executive.';
        }

        return null;
    }

    private function present(Offboarding $o): array
    {
        $today = Carbon::today();
        $user = $o->user;

        return [
            'id' => $o->id,
            'user_id' => $o->user_id,
            'status' => $o->status,
            'name' => $o->employee_name ?: ($user->name ?? 'Former employee'),
            'email' => $o->employee_email ?: ($user->email ?? null),
            'profile_pic' => $user->profile_pic ?? null,
            'employee_type' => $o->employee_type,
            'employee_team' => $o->employee_team,
            'joining_date' => $o->joining_date?->toDateString(),
            'reason_type' => $o->reason_type,
            'reason' => $o->reason,
            'notice_date' => ($o->notice_date ?: $o->created_at)?->toDateString(),
            'notice_label' => NoticePolicy::rule($o->employee_type, $o->reason_type)['label'],
            'last_working_day' => $o->last_working_day->toDateString(),
            'removal_date' => $o->removal_date->toDateString(),
            'days_to_last_day' => (int) $today->diffInDays($o->last_working_day, false),
            'days_to_removal' => (int) $today->diffInDays($o->removal_date, false),
            'initiated_by' => $o->initiator->name ?? null,
            'completed_at' => $o->completed_at?->toIso8601String(),
            'created_at' => $o->created_at?->toIso8601String(),
            'can_manage' => !($user && $this->refusal($user)),
        ];
    }

    /** GET /offboarding — everyone in the process, newest first, with counts for the tabs. */
    public function index(Request $request)
    {
        if ($r = $this->unavailable()) {
            return $r;
        }

        // Catch up on any account whose day has come, in case the daily job has not run.
        try {
            $this->svc()->processDue();
        } catch (\Throwable $e) {
            // never block the screen
        }

        $rows = Offboarding::with(['user', 'initiator'])->orderByDesc('id')->get();

        return response()->json([
            'supported' => true,
            'data' => $rows->map(fn ($o) => $this->present($o))->values(),
            'counts' => [
                'scheduled' => $rows->where('status', Offboarding::SCHEDULED)->count(),
                'completed' => $rows->where('status', Offboarding::COMPLETED)->count(),
                'other' => $rows->whereIn('status', [Offboarding::CANCELLED, Offboarding::REINSTATED])->count(),
            ],
            'reasons' => Offboarding::REASONS,
            'is_admin' => $this->actorIsAdmin(),
        ]);
    }

    /** GET /offboarding/people?search= — employees who can be offboarded. */
    public function people(Request $request)
    {
        if ($r = $this->unavailable()) {
            return $r;
        }

        $busy = array_keys(Offboarding::live());
        $q = User::where('user_role', 3)
            ->where('id', '!=', Auth::id())
            ->when($busy, fn ($w) => $w->whereNotIn('id', $busy))
            ->when(!$this->actorIsAdmin(), fn ($w) => $w->where(fn ($x) => $x->whereNull('employee_type')->orWhere('employee_type', '!=', 'Executive')))
            ->when($request->filled('search'), function ($w) use ($request) {
                $like = '%' . trim((string) $request->input('search')) . '%';
                $w->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'email', 'profile_pic', 'employee_type', 'employee_team', 'joining_date']);

        return response()->json(['supported' => true, 'data' => $q]);
    }

    /** GET /offboarding/preview?user_id=&last_working_day= — the payroll effect, before anything is saved. */
    public function preview(Request $request)
    {
        if ($r = $this->unavailable()) {
            return $r;
        }

        $data = $request->validate([
            'user_id' => 'required|integer',
            'last_working_day' => 'required|date',
            'reason_type' => 'nullable|in:' . implode(',', Offboarding::REASONS),
            'notice_date' => 'nullable|date',
        ]);
        $user = User::withTrashed()->findOrFail($data['user_id']);
        if ($msg = $this->refusal($user)) {
            return response()->json(['message' => $msg], 403);
        }
        PayrollAccess::guard($user->id);

        return response()->json(['data' => $this->svc()->payrollImpact($user, Carbon::parse($data['last_working_day']), $data['reason_type'] ?? null, $data['notice_date'] ?? null)]);
    }

    /** GET /offboarding/policy — the notice period the company policy gives this person for this reason. */
    public function policy(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|integer',
            'reason_type' => 'required|in:' . implode(',', Offboarding::REASONS),
            'notice_date' => 'nullable|date',
        ]);
        $user = User::withTrashed()->findOrFail($data['user_id']);
        if ($msg = $this->refusal($user)) {
            return response()->json(['message' => $msg], 403);
        }

        $rule = NoticePolicy::rule($user->employee_type, $data['reason_type']);
        $notice = Carbon::parse($data['notice_date'] ?? today())->toDateString();
        $last = NoticePolicy::suggestedLastDay($user->employee_type, $data['reason_type'], $notice);

        return response()->json(['data' => $rule + [
            'designation' => $user->employee_type,
            'notice_date' => $notice,
            'suggested_last_day' => $last?->toDateString(),
        ]]);
    }

    /** GET /offboarding/{id} — one record with its history and payroll effect. */
    public function show($id)
    {
        if ($r = $this->unavailable()) {
            return $r;
        }

        $o = Offboarding::with(['user', 'initiator', 'logs.actor'])->findOrFail($id);
        $impact = null;
        try {
            if ($o->user && !$this->refusal($o->user)) {
                PayrollAccess::guard($o->user_id);
                $impact = $this->svc()->payrollImpact($o->user, Carbon::parse($o->last_working_day), $o->reason_type, ($o->notice_date ?: $o->created_at)?->toDateString());
            }
        } catch (\Throwable $e) {
            $impact = null;
        }

        return response()->json([
            'data' => $this->present($o),
            'history' => $o->logs->map(fn ($l) => ['id' => $l->id, 'action' => $l->action, 'detail' => $l->detail, 'by' => $l->actor->name ?? 'System', 'at' => $l->created_at?->toIso8601String()])->values(),
            'payroll' => $impact,
        ]);
    }

    private function rules(bool $creating): array
    {
        return [
            'user_id' => ($creating ? 'required' : 'sometimes') . '|integer',
            'reason_type' => 'nullable|in:' . implode(',', Offboarding::REASONS),
            'reason' => 'nullable|string|max:1500',
            'last_working_day' => ($creating ? 'required' : 'nullable') . '|date',
            'notice_date' => 'nullable|date',
            'removal_date' => ($creating ? 'required' : 'nullable') . '|date',
        ];
    }

    /** Dates must make sense: the account cannot be switched off before the last working day. */
    private function dateProblem(?string $last, ?string $removal, $joining, ?string $notice = null): ?string
    {
        if (!$last || !$removal) {
            return null;
        }
        $l = Carbon::parse($last)->startOfDay();
        if ($notice && Carbon::parse($notice)->startOfDay()->gt($l)) {
            return 'The notice date cannot be after the last working day.';
        }
        $r = Carbon::parse($removal)->startOfDay();
        if ($r->lt($l)) {
            return 'The account cannot be removed before the last working day.';
        }
        if ($joining && $l->lt(Carbon::parse($joining)->startOfDay())) {
            return 'The last working day is before this person joined.';
        }

        return null;
    }

    /** POST /offboarding */
    public function store(Request $request)
    {
        if ($r = $this->unavailable()) {
            return response()->json($r->getData(true), 503);
        }

        $data = $request->validate($this->rules(true));
        $user = User::findOrFail($data['user_id']);
        if ($msg = $this->refusal($user)) {
            return response()->json(['message' => $msg], 403);
        }
        if (Offboarding::forUser($user->id)) {
            return response()->json(['message' => 'This person is already being offboarded.'], 422);
        }
        if ($msg = $this->dateProblem($data['last_working_day'], $data['removal_date'], $user->joining_date, $data['notice_date'] ?? null)) {
            return response()->json(['message' => $msg], 422);
        }

        $o = $this->svc()->schedule($user, $data);

        try {
            foreach (User::where('user_role', 2)->get(['id']) as $a) {
                if ((int) $a->id !== (int) Auth::id()) {
                    insertNotificationWithNature($a->id, Auth::id(), 'offboarding', ($o->employee_name ?? 'An employee') . ' is being offboarded (last working day ' . $o->last_working_day->format('j M Y') . ').', 'info', 0);
                }
            }
        } catch (\Throwable $e) {
            // courtesy only
        }

        return response()->json([
            'message' => $o->status === Offboarding::COMPLETED
                ? $o->employee_name . ' has been offboarded and the account is switched off.'
                : 'Offboarding scheduled. The account will be switched off on ' . $o->removal_date->format('j M Y') . '.',
            'data' => $this->present($o->load(['user', 'initiator'])),
        ], 201);
    }

    private function mine($id): Offboarding
    {
        $o = Offboarding::with('user')->findOrFail($id);
        if ($o->user && ($msg = $this->refusal($o->user))) {
            abort(403, $msg);
        }

        return $o;
    }

    /** PUT /offboarding/{id} — change the dates or reason of a scheduled offboarding. */
    public function update(Request $request, $id)
    {
        if ($r = $this->unavailable()) {
            return response()->json($r->getData(true), 503);
        }

        $o = $this->mine($id);
        if ($o->status !== Offboarding::SCHEDULED) {
            return response()->json(['message' => 'Only a scheduled offboarding can be changed.'], 422);
        }

        $data = $request->validate($this->rules(false));
        $last = $data['last_working_day'] ?? $o->last_working_day->toDateString();
        $removal = $data['removal_date'] ?? $o->removal_date->toDateString();
        if ($msg = $this->dateProblem($last, $removal, $o->joining_date, $data['notice_date'] ?? $o->notice_date?->toDateString())) {
            return response()->json(['message' => $msg], 422);
        }

        $o = $this->svc()->update($o, $data);

        return response()->json(['message' => $o->status === Offboarding::COMPLETED ? 'Saved. The account is switched off.' : 'Saved.', 'data' => $this->present($o->load(['user', 'initiator']))]);
    }

    /** POST /offboarding/{id}/cancel */
    public function cancel($id)
    {
        if ($r = $this->unavailable()) {
            return response()->json($r->getData(true), 503);
        }

        $o = $this->mine($id);
        if ($o->status !== Offboarding::SCHEDULED) {
            return response()->json(['message' => 'Only a scheduled offboarding can be cancelled.'], 422);
        }

        return response()->json(['message' => 'Offboarding cancelled. Nothing changes for ' . $o->employee_name . '.', 'data' => $this->present($this->svc()->cancel($o)->load(['user', 'initiator']))]);
    }

    /** POST /offboarding/{id}/remove-now — switch the account off today instead of waiting. */
    public function removeNow($id)
    {
        if ($r = $this->unavailable()) {
            return response()->json($r->getData(true), 503);
        }

        $o = $this->mine($id);
        if ($o->status !== Offboarding::SCHEDULED) {
            return response()->json(['message' => 'This account has already been handled.'], 422);
        }
        // Switching off early must never leave the account removed while the person is still being paid for working.
        if ($o->last_working_day->gt(Carbon::today())) {
            return response()->json(['message' => 'They work until ' . $o->last_working_day->format('j M Y') . '. Change the dates first if they are leaving earlier.'], 422);
        }

        $o = $this->svc()->removeAccount($o->fresh(), Auth::id(), 'removed');

        return response()->json(['message' => $o->employee_name . "'s account has been switched off.", 'data' => $this->present($o->load(['user', 'initiator']))]);
    }

    /** POST /offboarding/{id}/reinstate — bring a removed account back. */
    public function reinstate($id)
    {
        if ($r = $this->unavailable()) {
            return response()->json($r->getData(true), 503);
        }

        $o = $this->mine($id);
        if ($o->status !== Offboarding::COMPLETED) {
            return response()->json(['message' => 'Only a removed account can be reinstated.'], 422);
        }

        return response()->json(['message' => $o->employee_name . ' has been reinstated.', 'data' => $this->present($this->svc()->reinstate($o)->load(['user', 'initiator']))]);
    }

    /** POST /offboarding/process-due — run the daily job by hand. */
    public function processDue()
    {
        if ($r = $this->unavailable()) {
            return response()->json($r->getData(true), 503);
        }

        $n = $this->svc()->processDue();

        return response()->json(['message' => $n ? "{$n} account" . ($n === 1 ? '' : 's') . ' switched off.' : 'Nothing was due today.', 'removed' => $n]);
    }
}
