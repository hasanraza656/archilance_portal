<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use App\Models\ManagerEvaluation;
use App\Models\User;
use App\Services\ManagerRatingService;
use App\Support\ManagerEvaluationQuestions as Q;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Bi-weekly manager / executive ratings (see ManagerRatingService for who rates
 * whom and how scores are weighted).
 *
 *   GET  /employee/manager-evaluations/pending    what the user still owes (time tracker)
 *   POST /employee/manager-evaluations            submit one rating (or "can't rate")
 *   GET  /employee/manager-evaluations/my-score   the user's OWN final score + areas to improve
 *   GET  /admin/manager-evaluations               admin only: every rating, with who gave it
 *
 * Anonymity: apart from the admin endpoint, nothing here ever reveals who gave
 * which score — a person only ever receives their final score and the names of
 * their weakest areas.
 *
 * Everything degrades to "nothing" until the `manager_evaluations` table exists,
 * so the backend can be deployed before the SQL is run.
 */
class ManagerEvaluationController extends Controller
{
    private function service(): ManagerRatingService
    {
        return app(ManagerRatingService::class);
    }

    public function pending()
    {
        $items = $this->service()->pending(Auth::user(), Carbon::today());

        return response()->json([
            'has_pending' => count($items) > 0,
            'total_pending' => count($items),
            'next' => $items[0] ?? null,
            'pending' => $items,
        ]);
    }

    public function store(Request $request)
    {
        $me = Auth::user();
        $svc = $this->service();

        if (!$svc->tableReady()) {
            return response()->json(['message' => 'Manager ratings are not available yet.'], 503);
        }

        $validator = Validator::make($request->all(), [
            'ratee_id' => 'required|integer|exists:users,id',
            'month_start' => 'required|date',
            'skipped' => 'sometimes|boolean',
            'scores' => 'nullable|array',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // `month_start` / `month` are the field names the clients already use; the value is now the
        // first day (a Monday) of a 14-day period.
        $month = $svc->periodStart($request->month_start);
        if ($month->gte($svc->periodStart(Carbon::today()))) {
            return response()->json(['message' => 'That rating period has not finished yet.'], 422);
        }

        // The server — not the client — decides whether this rating is allowed
        // and which question set it uses.
        $allowed = $svc->eligible($me, $month);
        $rateeId = (int) $request->ratee_id;
        if (!isset($allowed[$rateeId])) {
            return response()->json(['message' => 'You are not asked to rate this person for that period.'], 403);
        }
        $kind = $allowed[$rateeId]['kind'];

        $skipped = $request->boolean('skipped');
        $scores = null;
        $average = null;

        if (!$skipped) {
            $given = (array) $request->input('scores', []);
            $clean = [];

            foreach (Q::keysFor($kind) as $key) {
                $v = $given[$key] ?? null;
                if (!is_numeric($v) || (int) $v != $v || (int) $v < 1 || (int) $v > Q::MAX_SCORE) {
                    return response()->json(['message' => 'Please score every question from 1 to ' . Q::MAX_SCORE . '.'], 422);
                }
                $clean[$key] = (int) $v;
            }

            $scores = $clean;
            $average = round(array_sum($clean) / count($clean), 2);
        }

        $already = 'You have already rated this person for that period.';

        if (ManagerEvaluation::where('rater_id', $me->id)->where('ratee_id', $rateeId)->whereDate('month_start', $month->toDateString())->exists()) {
            return response()->json(['message' => $already], 422);
        }

        try {
            ManagerEvaluation::create([
                'rater_id' => $me->id,
                'ratee_id' => $rateeId,
                'month_start' => $month->toDateString(),
                'kind' => $kind,
                'skipped' => $skipped,
                'scores' => $scores,
                'average' => $average,
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23000') {
                return response()->json(['message' => $already], 422);
            }
            throw $e;
        }

        // Deliberately minimal: the rater is told it was saved, nothing more.
        return response()->json(['message' => 'Rating submitted.'], 201);
    }

    /**
     * The signed-in user's own results — final score and weakest areas only, for
     * the last few periods that have at least one real rating.
     */
    public function myScore()
    {
        $me = Auth::user();
        $svc = $this->service();

        if (!$svc->tableReady()) {
            return response()->json(['months' => []]);
        }

        $months = ManagerEvaluation::where('ratee_id', $me->id)
            ->where('skipped', false)
            ->orderByDesc('month_start')
            ->pluck('month_start')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->unique()
            ->take(6)
            ->values();

        $out = $months->map(function ($m) use ($me, $svc) {
            $month = Carbon::parse($m);
            $r = $svc->score($me, $month);

            return [
                'month_start' => $month->toDateString(),
                'month_label' => $svc->periodLabel($month),
                'final_score' => $r['final'],
                'weak_areas' => $r['weak_areas'],
            ];
        })->filter(fn ($row) => $row['final_score'] !== null)->values();

        return response()->json(['months' => $out]);
    }

    /**
     * Admin only (the route sits in the role:2 group): every rating of a period,
     * who gave it, plus each person's rolled-up score by group.
     */
    public function adminIndex(Request $request)
    {
        $svc = $this->service();

        if (!$svc->tableReady()) {
            return response()->json(['supported' => false, 'months' => [], 'month' => null, 'summaries' => [], 'ratings' => [], 'questions' => []]);
        }

        $available = ManagerEvaluation::orderByDesc('month_start')->pluck('month_start')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->unique()->values();

        $month = $request->filled('month')
            ? $svc->periodStart($request->month)
            : ($available->isNotEmpty() ? Carbon::parse($available->first()) : $svc->periodStart(Carbon::today())->subDays(ManagerRatingService::PERIOD_DAYS));

        $rows = ManagerEvaluation::with(['rater:id,name,employee_type', 'ratee:id,name,profile_pic,employee_type'])
            ->whereDate('month_start', $month->toDateString())
            ->orderBy('ratee_id')
            ->get();

        $summaries = $rows->pluck('ratee')->filter()->unique('id')->map(function ($ratee) use ($svc, $month) {
            $r = $svc->score($ratee, $month);

            return [
                'ratee_id' => $ratee->id,
                'ratee_name' => $ratee->name,
                'ratee_profile_pic' => $ratee->profile_pic,
                'ratee_type' => $ratee->employee_type,
                'final_score' => $r['final'],
                'groups' => $r['groups'],
                'areas' => $r['areas'],
                'weak_areas' => $r['weak_areas'],
            ];
        })->sortBy('ratee_name')->values();

        $ratings = $rows->map(fn ($r) => [
            'id' => $r->id,
            'rater_id' => $r->rater_id,
            'rater_name' => $r->rater->name ?? null,
            'ratee_id' => $r->ratee_id,
            'ratee_name' => $r->ratee->name ?? null,
            'kind' => $r->kind,
            'skipped' => (bool) $r->skipped,
            'average' => $r->average,
            'scores' => $r->scores,
        ])->values();

        return response()->json([
            'supported' => true,
            'month' => $month->toDateString(),
            'month_label' => $svc->periodLabel($month),
            'months' => $available,
            'summaries' => $summaries,
            'ratings' => $ratings,
            'questions' => Q::all(),
        ]);
    }
}
