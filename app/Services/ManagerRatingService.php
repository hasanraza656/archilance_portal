<?php

namespace App\Services;

use App\Models\ManagerEvaluation;
use App\Models\User;
use App\Support\ManagerEvaluationQuestions as Q;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Bi-weekly manager / executive ratings: who owes which rating, and how the
 * scores roll up into one final score.
 *
 * WHO rates WHOM
 *  - Team members rate every PROJECT MANAGER they tracked more than 10 hours for
 *    in the two-week period (total across that manager's projects; ProjectManagerHours,
 *    strict mode — real project managers only, no reporting-line fallback).
 *  - Executives rate the managers of their team, and those managers rate their
 *    executive (org chart: users.employee_team). Managers of the same team also
 *    rate each other; a team with a single manager has no peers.
 *
 * FINAL SCORE (out of 10): a manager is Executive 40% + Team members 40% + Peer
 * managers 20%; an executive is Managers 50% + Team members 50%. A group with no
 * ratings that period drops out and the others are scaled back up to 100%.
 *
 * PERIODS: ratings run on fixed 14-day blocks (Monday to the Sunday 13 days later)
 * counted from START_PERIOD, and a period is asked about on the Monday after it
 * ends. Employees are rated WEEKLY by their project managers
 * (EmployeeEvaluationController); only managers and executives are rated
 * fortnightly. With START_PERIOD = 28 Sep 2026 the first round is Monday 12 Oct
 * (covering 28 Sep – 11 Oct), then 26 Oct, 9 Nov, …
 * Everything is anonymous to everyone but admin — callers expose only the final
 * score and the weakest areas, never who scored what.
 */
class ManagerRatingService
{
    /**
     * First day (a Monday) of the first 14-day period; every later period starts a
     * multiple of 14 days after it. The first period to be rated is the one that
     * ends on Sunday 11 Oct 2026, asked about on Monday 12 Oct.
     */
    public const START_PERIOD = '2026-09-28';

    /** Length of a rating period, in days. */
    public const PERIOD_DAYS = 14;

    /** How many finished periods back someone is still asked about. */
    public const MAX_LOOKBACK_PERIODS = 2;

    /** Accounts left out entirely (a test account). */
    public const IGNORED_USER_IDS = [58];

    public function tableReady(): bool
    {
        static $ready = null;

        if ($ready === null) {
            try {
                $ready = Schema::hasTable('manager_evaluations');
            } catch (\Throwable $e) {
                $ready = false;
            }
        }

        return $ready;
    }

    private function teamOf($user): ?string
    {
        $t = strtolower(trim((string) ($user->employee_team ?? '')));

        return $t === '' ? null : $t;
    }

    private function usable($user): bool
    {
        return $user
            && (int) $user->user_role === 3
            && !in_array((int) $user->id, self::IGNORED_USER_IDS, true);
    }

    /** First day (Monday) of the 14-day period containing $date. */
    public function periodStart($date): Carbon
    {
        $anchor = Carbon::parse(self::START_PERIOD)->startOfDay();
        $day = Carbon::parse($date)->startOfDay();

        $periods = (int) floor($anchor->diffInDays($day, false) / self::PERIOD_DAYS);

        return $anchor->copy()->addDays($periods * self::PERIOD_DAYS);
    }

    /** Last day (Sunday) of the period that starts on $start. */
    public function periodEnd(Carbon $start): Carbon
    {
        return $start->copy()->addDays(self::PERIOD_DAYS - 1)->endOfDay();
    }

    /** "28 Sep – 11 Oct 2026" — what people see instead of a month name. */
    public function periodLabel(Carbon $start): string
    {
        $end = $this->periodEnd($start);

        $left = $start->month === $end->month ? $start->format('j') : $start->format('j M');

        return $left . ' – ' . $end->format('j M Y');
    }

    /** Finished periods this rater can still be asked about, oldest first. */
    public function askablePeriods(Carbon $today): array
    {
        $current = $this->periodStart($today);
        $first = $this->periodStart(self::START_PERIOD);
        $floor = $current->copy()->subDays(self::MAX_LOOKBACK_PERIODS * self::PERIOD_DAYS);
        if ($floor->gt($first)) {
            $first = $floor;
        }

        $periods = [];
        for ($p = $first->copy(); $p->lt($current); $p->addDays(self::PERIOD_DAYS)) {
            $periods[] = $p->copy();
        }

        return $periods;
    }

    /**
     * Everyone $rater may rate for the period starting $monthStart (done or not), as ratee_id => kind.
     *
     * @return array<int, array{kind: string, seconds: int}>
     */
    public function eligible(User $rater, Carbon $monthStart): array
    {
        if (!$this->usable($rater)) {
            return [];
        }

        $out = [];
        $type = $rater->employee_type;
        $team = $this->teamOf($rater);

        // Team member -> project managers worked for (>10 h in the two-week period).
        if (!in_array($type, ['Manager', 'Executive'], true)) {
            $credits = app(ProjectManagerHours::class)->credits($monthStart, $this->periodEnd($monthStart), 'all', false);

            $byManager = [];
            foreach ($credits as $c) {
                if ((int) $c['employee_id'] === (int) $rater->id) {
                    $byManager[$c['manager_id']] = ($byManager[$c['manager_id']] ?? 0) + $c['seconds'];
                }
            }

            foreach ($byManager as $managerId => $seconds) {
                if ($seconds > ProjectManagerHours::PERIOD_MIN_SECONDS && (int) $managerId !== (int) $rater->id) {
                    $out[(int) $managerId] = ['kind' => Q::TEAM, 'seconds' => (int) $seconds];
                }
            }
        }

        // Org chart: executive <-> managers of the same team; managers <-> managers.
        if ($team !== null && in_array($type, ['Manager', 'Executive'], true)) {
            $sameTeam = User::where('user_role', 3)
                ->whereIn('employee_type', ['Manager', 'Executive'])
                ->where('id', '!=', $rater->id)
                ->whereRaw('LOWER(TRIM(employee_team)) = ?', [$team])
                ->get(['id', 'employee_type', 'user_role']);

            foreach ($sameTeam as $other) {
                if (!$this->usable($other)) {
                    continue;
                }

                if ($type === 'Executive' && $other->employee_type === 'Manager') {
                    $out[(int) $other->id] = ['kind' => Q::EXEC_TO_MANAGER, 'seconds' => 0];
                } elseif ($type === 'Manager' && $other->employee_type === 'Executive') {
                    $out[(int) $other->id] = ['kind' => Q::MANAGER_TO_EXEC, 'seconds' => 0];
                } elseif ($type === 'Manager' && $other->employee_type === 'Manager') {
                    $out[(int) $other->id] = ['kind' => Q::PEER, 'seconds' => 0];
                }
            }
        }

        // Never rate a test/ignored account.
        foreach (self::IGNORED_USER_IDS as $id) {
            unset($out[$id]);
        }

        return $out;
    }

    /**
     * What $rater still owes, oldest period first.
     *
     * @return array<int, array>
     */
    public function pending(User $rater, Carbon $today): array
    {
        if (!$this->tableReady()) {
            return [];
        }

        $items = [];

        foreach ($this->askablePeriods($today) as $month) {
            $eligible = $this->eligible($rater, $month);
            if (empty($eligible)) {
                continue;
            }

            $done = ManagerEvaluation::where('rater_id', $rater->id)
                ->whereDate('month_start', $month->toDateString())
                ->pluck('ratee_id')
                ->map(fn ($id) => (int) $id)
                ->flip();

            $people = User::whereIn('id', array_keys($eligible))->get(['id', 'name', 'email', 'profile_pic', 'employee_type'])->keyBy('id');

            foreach ($eligible as $rateeId => $info) {
                if (isset($done[$rateeId]) || !$people->has($rateeId)) {
                    continue;
                }

                $p = $people->get($rateeId);
                $items[] = [
                    'ratee_id' => (int) $rateeId,
                    'ratee_name' => $p->name,
                    'ratee_email' => $p->email,
                    'ratee_profile_pic' => $p->profile_pic,
                    'ratee_type' => $p->employee_type,
                    'kind' => $info['kind'],
                    'heading' => Q::all()[$info['kind']]['label'],
                    'month_start' => $month->toDateString(),
                    'month_label' => $this->periodLabel($month),
                    'worked_seconds' => $info['seconds'],
                    'questions' => Q::questionsFor($info['kind']),
                ];
            }
        }

        usort($items, fn ($a, $b) => [$a['month_start'], $a['kind'], strcasecmp((string) $a['ratee_name'], (string) $b['ratee_name'])]
            <=> [$b['month_start'], $b['kind'], 0]);

        return $items;
    }

    /**
     * Roll the ratings for one ratee and period up into a final score.
     *
     * @return array{final: ?float, groups: array<string, array{score: float, raters: int, weight: int}>, areas: array<string, float>, weak_areas: string[]}
     */
    public function score(User $ratee, Carbon $monthStart): array
    {
        $rows = ManagerEvaluation::where('ratee_id', $ratee->id)
            ->whereDate('month_start', $monthStart->toDateString())
            ->where('skipped', false)
            ->get();

        $weights = $ratee->employee_type === 'Executive' ? Q::EXECUTIVE_WEIGHTS : Q::MANAGER_WEIGHTS;

        $groups = [];
        $areaValues = [];

        foreach ($rows->groupBy('kind') as $kind => $kindRows) {
            if (!isset($weights[$kind])) {
                continue;
            }

            $groups[$kind] = [
                'score' => round($kindRows->avg('average'), 2),
                'raters' => $kindRows->count(),
                'weight' => $weights[$kind],
            ];

            $titles = Q::titlesFor($kind);
            foreach ($kindRows as $r) {
                foreach (($r->scores ?? []) as $key => $value) {
                    if (isset($titles[$key])) {
                        $areaValues[$titles[$key]][] = (float) $value;
                    }
                }
            }
        }

        $totalWeight = array_sum(array_column($groups, 'weight'));
        $final = null;
        if ($totalWeight > 0) {
            $sum = 0.0;
            foreach ($groups as $g) {
                $sum += $g['score'] * $g['weight'];
            }
            $final = round($sum / $totalWeight, 2);
        }

        $areas = [];
        foreach ($areaValues as $title => $vals) {
            $areas[$title] = round(array_sum($vals) / count($vals), 2);
        }
        asort($areas);

        $weak = array_keys(array_filter($areas, fn ($v) => $v < Q::WEAK_BELOW));
        $weak = array_slice($weak, 0, Q::MAX_WEAK_AREAS);

        return ['final' => $final, 'groups' => $groups, 'areas' => $areas, 'weak_areas' => $weak];
    }
}
