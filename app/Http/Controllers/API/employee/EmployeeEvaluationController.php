<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use App\Models\EmployeeEvaluation;
use App\Models\User;
use App\Services\ProjectManagerHours;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * Weekly employee evaluations — a project manager rates the people who worked
 * for them.
 *
 * WHO rates WHOM is decided by the work done, not by the org chart: an employee
 * is rated, once per finished week (Monday–Sunday), by every project manager
 * they tracked MORE than 5 hours for that week (see ProjectManagerHours — it
 * also describes the fallback to the reporting manager for projects that have no
 * manager set yet). Two managers => two ratings; the employee's weekly score is
 * their average. Leave approval stays with the reporting manager; this does not.
 *
 * One overall evaluation per (employee, manager, week), six criteria scored
 * 1–10, weighted into an overall percentage (EmployeeEvaluation::WEIGHTS).
 *
 * The desktop time tracker calls pending() on start-up and locks the timer
 * until everything outstanding is rated. Weeks the manager missed stay
 * outstanding, oldest first, up to MAX_LOOKBACK_WEEKS back.
 *
 * Everything here is additive: until the `employee_evaluations` table exists,
 * every endpoint answers "nothing pending / nothing recorded" instead of
 * erroring, so the backend can be deployed before the SQL is run.
 */
class EmployeeEvaluationController extends Controller
{
    /** Employee types that are never rated weekly (managers rate; internees have their own module). */
    private const NOT_EVALUATED_TYPES = ['Manager', 'Executive', 'Internee'];

    /**
     * First week that is ever asked about (a Monday). Stops the very first
     * deploy from demanding evaluations for weeks that predate the feature.
     */
    private const START_WEEK = '2026-10-05';

    /** How many finished weeks back a returning manager is asked about, at most. */
    private const MAX_LOOKBACK_WEEKS = 4;

    /** Accounts left out of ratings entirely (a test account). */
    private const IGNORED_USER_IDS = [58];

    /** Admins (role 2) and Executives see every evaluation in the company. */
    private function isElevated($user): bool
    {
        return (int) $user->user_role === 2 || $user->employee_type === 'Executive';
    }

    /**
     * Individual question scores are for ADMINS only. Everyone else — employees,
     * managers (even for ratings they gave themselves) and executives — only ever
     * receives the overall score (`overall_percent`) and comments. This is
     * enforced here, in the API response, not just hidden in the UI: anything in
     * the response can be read from the browser's network tab.
     */
    private function redactScores(array $row): array
    {
        foreach (array_keys(EmployeeEvaluation::WEIGHTS) as $field) {
            unset($row[$field]);
        }
        unset($row['average_rating']); // it is derived from the individual scores

        return $row;
    }

    private function tableReady(): bool
    {
        static $ready = null;

        if ($ready === null) {
            try {
                $ready = Schema::hasTable('employee_evaluations');
            } catch (\Throwable $e) {
                $ready = false;
            }
        }

        return $ready;
    }

    /** Monday 00:00 of the week containing $date. */
    private function mondayOf($date): Carbon
    {
        return Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    /** Whether $user can be the SUBJECT of a weekly rating. */
    private function isEvaluable($user): bool
    {
        return $user
            && (int) $user->user_role === 3
            && !in_array((int) $user->id, self::IGNORED_USER_IDS, true)
            && !in_array($user->employee_type, self::NOT_EVALUATED_TYPES, true);
    }

    private function emptyPending(bool $isRater, ?Carbon $from = null, ?Carbon $to = null): array
    {
        return [
            'is_manager' => $isRater,
            'has_pending' => false,
            'total_pending' => 0,
            'date_from' => $from?->toDateString(),
            'date_to' => $to?->toDateString(),
            'next' => null,
            'pending' => [],
        ];
    }

    /**
     * Weekly credits for one finished range, narrowed to ratings that are due:
     * more than the weekly minimum for that manager.
     *
     * @return array<int, array> rows of [bucket, employee_id, manager_id, seconds, root_ids]
     */
    private function dueCredits(Carbon $from, Carbon $to): array
    {
        $rows = app(ProjectManagerHours::class)->credits($from, $to, 'week');

        return array_values(array_filter($rows, fn ($r) => $r['seconds'] > ProjectManagerHours::WEEKLY_MIN_SECONDS));
    }

    /**
     * GET /employee/evaluations/reportees
     * People the signed-in user rates: their reporting line plus anyone they've
     * rated for work on their projects (drives the filter on the manager page).
     */
    public function reportees()
    {
        $user = Auth::user();

        $ratedIds = $this->tableReady()
            ? EmployeeEvaluation::where('manager_id', $user->id)->distinct()->pluck('employee_id')
            : collect();

        $people = User::where('user_role', 3)
            ->where(function ($q) use ($user, $ratedIds) {
                $q->where('manager_id', $user->id)->orWhereIn('id', $ratedIds);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'profile_pic', 'employee_type']);

        return response()->json(['data' => $people]);
    }

    /**
     * GET /{admin|employee}/evaluations/filters
     *
     * Dropdown options for the evaluations pages. Admins and executives get
     * everyone who has ever rated / been rated; anyone else gets only the people
     * they have rated (so the list can never reveal people outside it).
     */
    public function filters()
    {
        $user = Auth::user();

        $columns = ['id', 'name', 'profile_pic'];

        if (!$this->tableReady()) {
            return response()->json(['managers' => [], 'employees' => []]);
        }

        if ($this->isElevated($user)) {
            $managers = User::whereIn('id', EmployeeEvaluation::query()->select('manager_id')->distinct())
                ->orderBy('name')->get($columns);
            $employees = User::whereIn('id', EmployeeEvaluation::query()->select('employee_id')->distinct())
                ->orderBy('name')->get($columns);
        } else {
            $managers = collect();
            $employees = User::whereIn('id', EmployeeEvaluation::where('manager_id', $user->id)->select('employee_id')->distinct())
                ->orderBy('name')->get($columns);
        }

        return response()->json(['managers' => $managers, 'employees' => $employees]);
    }

    /**
     * GET /employee/evaluations/pending
     *
     * Everything the signed-in user still owes as a PROJECT MANAGER, oldest week
     * first: one entry per (employee, week) where the employee tracked more than
     * 5 hours for them that week and no rating by them exists yet.
     */
    public function pending(Request $request)
    {
        $me = Auth::user();

        if (!$this->tableReady()) {
            return response()->json($this->emptyPending(false));
        }

        // Only weeks that have fully finished are ever asked about.
        $currentMonday = $this->mondayOf(Carbon::today());
        $lastSunday = $currentMonday->copy()->subDay();

        $earliest = $this->mondayOf(self::START_WEEK);
        $lookbackFloor = $currentMonday->copy()->subWeeks(self::MAX_LOOKBACK_WEEKS);
        if ($lookbackFloor->gt($earliest)) {
            $earliest = $lookbackFloor;
        }

        if ($earliest->gt($lastSunday)) {
            return response()->json($this->emptyPending(false, $earliest, $lastSunday));
        }

        $mine = array_values(array_filter(
            $this->dueCredits($earliest, $lastSunday),
            fn ($r) => (int) $r['manager_id'] === (int) $me->id
        ));

        if (empty($mine)) {
            return response()->json($this->emptyPending(false, $earliest, $lastSunday));
        }

        $people = User::whereIn('id', collect($mine)->pluck('employee_id')->unique())
            ->where('user_role', 3)
            ->get(['id', 'name', 'email', 'profile_pic', 'employee_type', 'user_role'])
            ->filter(fn ($u) => $this->isEvaluable($u))
            ->keyBy('id');

        $done = EmployeeEvaluation::where('manager_id', $me->id)
            ->whereBetween('week_start', [$earliest->toDateString(), $lastSunday->toDateString()])
            ->get(['employee_id', 'week_start'])
            ->map(fn ($e) => $e->week_start->toDateString() . '|' . $e->employee_id)
            ->flip();

        $open = array_values(array_filter($mine, fn ($r) => $people->has($r['employee_id'])
            && !isset($done[$r['bucket'] . '|' . $r['employee_id']])));

        if (empty($open)) {
            return response()->json($this->emptyPending(true, $earliest, $lastSunday));
        }

        $titles = app(ProjectManagerHours::class)->rootTitles(collect($open)->pluck('root_ids')->flatten()->unique()->all());

        $pending = collect($open)
            ->sortBy([['bucket', 'asc'], ['employee_id', 'asc']])
            ->map(function ($r) use ($people, $titles) {
                $p = $people->get($r['employee_id']);

                return [
                    'employee_id' => $r['employee_id'],
                    'employee_name' => $p->name ?? null,
                    'employee_email' => $p->email ?? null,
                    'employee_profile_pic' => $p->profile_pic ?? null,
                    'week_start' => $r['bucket'],
                    'week_end' => Carbon::parse($r['bucket'])->addDays(6)->toDateString(),
                    'worked_seconds' => (int) $r['seconds'],
                    'projects' => collect($r['root_ids'])->map(fn ($id) => $titles[$id] ?? null)->filter()->sort()->values(),
                ];
            })
            ->values();

        return response()->json([
            'is_manager' => true,
            'has_pending' => true,
            'total_pending' => $pending->count(),
            'date_from' => $earliest->toDateString(),
            'date_to' => $lastSunday->toDateString(),
            'next' => $pending->first(),
            'pending' => $pending,
        ]);
    }

    /**
     * GET /employee/evaluations
     * Filters: employee_id, manager_id, week_start, date_from, date_to
     *
     * A manager sees what they gave, an employee sees what they received, and an
     * Executive or admin sees everything.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        if (!$this->tableReady()) {
            return response()->json(
                (new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20, 1, [
                    'path' => $request->url(),
                    'query' => $request->query(),
                ]))->toArray()
            );
        }

        $query = EmployeeEvaluation::with([
            'manager:id,name,email,profile_pic',
            'employee:id,name,email,profile_pic',
        ]);

        if (!$this->isElevated($user)) {
            $query->where(function ($q) use ($user) {
                $q->where('manager_id', $user->id)
                    ->orWhere('employee_id', $user->id);
            });
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
        if ($request->filled('manager_id')) {
            $query->where('manager_id', $request->manager_id);
        }
        if ($request->filled('week_start')) {
            $query->whereDate('week_start', $this->mondayOf($request->week_start)->toDateString());
        }
        if ($request->filled('date_from')) {
            // Weeks, not days: snap to that week's Monday so a mid-week start
            // date still includes the week it falls in.
            $query->whereDate('week_start', '>=', $this->mondayOf($request->date_from)->toDateString());
        }
        if ($request->filled('date_to')) {
            $query->whereDate('week_start', '<=', $request->date_to);
        }

        $payload = $query->orderBy('week_start', 'desc')->orderBy('id', 'desc')->paginate(20)->toArray();

        if ((int) $user->user_role !== 2) {
            $payload['data'] = array_map(fn ($row) => $this->redactScores($row), $payload['data']);
        }

        return response()->json($payload);
    }

    /**
     * POST /employee/evaluations
     */
    public function store(Request $request)
    {
        $me = Auth::user();

        if (!$this->tableReady()) {
            return response()->json(['message' => 'Evaluations are not available yet.'], 503);
        }

        $rules = [
            'employee_id' => 'required|integer|exists:users,id',
            'week_start' => 'required|date',
            'comments' => 'nullable|string|max:2000',
        ];
        foreach (array_keys(EmployeeEvaluation::WEIGHTS) as $field) {
            $rules[$field] = 'required|integer|min:1|max:' . EmployeeEvaluation::MAX_SCORE;
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $weekStart = $this->mondayOf($request->week_start);
        if ($weekStart->gte($this->mondayOf(Carbon::today()))) {
            return response()->json(['message' => 'That week has not finished yet.'], 422);
        }

        $employee = User::find((int) $request->employee_id);
        if (!$this->isEvaluable($employee)) {
            return response()->json(['message' => 'This person is not rated in the weekly evaluation.'], 422);
        }

        // The rater must actually be one of this employee's project managers
        // for that week (more than the weekly minimum of tracked time).
        $eligible = collect($this->dueCredits($weekStart, $weekStart->copy()->addDays(6)))->contains(
            fn ($r) => (int) $r['manager_id'] === (int) $me->id && (int) $r['employee_id'] === (int) $employee->id
        );
        if (!$eligible) {
            return response()->json(['message' => 'You did not manage this employee\'s work in that week.'], 403);
        }

        $alreadyDone = 'This week has already been evaluated for this employee.';

        $exists = EmployeeEvaluation::where('employee_id', $employee->id)
            ->where('manager_id', $me->id)
            ->whereDate('week_start', $weekStart->toDateString())
            ->exists();
        if ($exists) {
            return response()->json(['message' => $alreadyDone], 422);
        }

        $data = [
            'manager_id' => $me->id,
            'employee_id' => $employee->id,
            'week_start' => $weekStart->toDateString(),
            'comments' => $request->comments,
        ];
        foreach (array_keys(EmployeeEvaluation::WEIGHTS) as $field) {
            $data[$field] = (int) $request->input($field);
        }

        try {
            $evaluation = EmployeeEvaluation::create($data);
        } catch (QueryException $e) {
            // Unique index hit: a double-click / retry raced the check above.
            // (On a database still on the old one-per-employee-week index this
            // also fires for a SECOND manager — run the upgrade SQL.)
            if (($e->errorInfo[0] ?? null) === '23000') {
                return response()->json(['message' => $alreadyDone], 422);
            }
            throw $e;
        }

        // Only an admin may see individual scores; the rater just needs to know it was saved.
        return response()->json([
            'message' => 'Evaluation submitted successfully.',
            'data' => ['id' => $evaluation->id, 'week_start' => $evaluation->week_start->toDateString()],
        ], 201);
    }
}
