<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Traits\CountsWeekdays;
use Carbon\Carbon;

/**
 * Single source of truth for the Archilance LLC Leave & Time-Off Policy
 * (effective 1 August 2026) — entitlements, day counting and every rule that
 * decides whether a request may be submitted.
 *
 * Both leave controllers delegate here so the employee-facing checks and the
 * admin-facing balance summaries can never drift apart.
 *
 * ── ENTITLEMENTS (per leave year = joining-date anniversary, no carry-over) ──
 *   Annual      10 days   calendar days (weekends inside the block are consumed)
 *   Casual      10 days   working days
 *   Additional   8 days   working days   — BIM TEAM ONLY, own pool, does not
 *                                           share a cap with Casual (public
 *                                           holiday compensation — the policy
 *                                           states "10 + 8 = 18 Casual days
 *                                           total"; tracked here as two
 *                                           independent 10 and 8 balances that
 *                                           add up to the same total)
 *   Sick         8 days   working days
 *   Marriage    15 days   calendar days  — ONCE PER EMPLOYMENT, not per year
 *   Maternity   90 days   calendar days  — FEMALE only, per birth (not per year).
 *                                           First 60 days paid, the optional
 *                                           third month unpaid.
 *   Paternity    7 days   calendar days  — MALE only, per birth (not per year)
 *   Unpaid      no cap    calendar days  — fallback for anything beyond the above
 *
 * ── RULES ────────────────────────────────────────────────────────────────────
 *   Annual     · not available during probation
 *              · at least 7 days' notice (management may override — see the
 *                admin controller's on-behalf endpoint)
 *              · two annual blocks in one cycle need a 7 full calendar day gap
 *              · may not run straight into casual/additional/sick (no working
 *                day between)
 *   Casual     · max 2 consecutive days per block
 *              · 2 real working days between blocks of the SAME type (weekends/
 *                other leave do not count — dates are verified here, "actually
 *                worked" stays HR's call)
 *              · may not run straight into annual
 *              · max 3 days during the whole probation period
 *   Additional · identical restrictions to Casual (max 2 consecutive, 2-day
 *                gap, may not run into annual) but checked against its OWN
 *                8-day balance — BIM Team only, no probation rule
 *   Sick       · unrestricted during probation
 *              · may not run straight into annual
 *   Marriage   · 15 calendar days, once per employment; the rest goes to Unpaid
 *   Maternity  · 3 months (90 calendar days) per birth. The first 2 months are
 *                paid; a third month may be taken unpaid. Coordinate with HR in
 *                advance. Female employees.
 *   Paternity  · 1 week (7 calendar days) fully paid, per birth. Notify HR in
 *                advance where reasonably possible. Male employees.
 *
 * Maternity and Paternity are administered SEPARATELY from Annual, Casual and
 * Sick — they draw on their own balances and never touch those.
 *
 * NOTE ON PAY: this class models ENTITLEMENT and eligibility only. The paid /
 * unpaid split inside Maternity is surfaced for display (see summary()'s
 * `paid_days` and the note) but no salary logic is applied anywhere yet — that
 * is deliberate and was agreed as out of scope for this change.
 *
 * Internees and the Outsource Department are outside this policy entirely.
 */
class LeavePolicy
{
    use CountsWeekdays;

    // ── Canonical leave types ────────────────────────────────────────────────
    public const ANNUAL     = 'annual';
    public const CASUAL     = 'casual';
    public const ADDITIONAL = 'additional'; // BIM Team only — its own pool
    public const SICK       = 'sick';
    public const MARRIAGE   = 'marriage';
    public const MATERNITY  = 'maternity'; // female employees
    public const PATERNITY  = 'paternity'; // male employees
    public const UNPAID     = 'unpaid';

    /** Types a request may be submitted as, in display order. */
    public const TYPES = [
        self::CASUAL, self::ADDITIONAL, self::ANNUAL, self::SICK,
        self::MARRIAGE, self::MATERNITY, self::PATERNITY, self::UNPAID,
    ];

    // ── Entitlements ─────────────────────────────────────────────────────────
    public const ANNUAL_DAYS     = 10;
    public const CASUAL_DAYS     = 10; // everyone, BIM Team included
    public const ADDITIONAL_DAYS = 8;  // BIM Team only, separate pool
    public const SICK_DAYS       = 8;
    public const MARRIAGE_DAYS   = 15; // lifetime, not per cycle

    // 3 months, expressed in calendar days so the maths never depends on which
    // months the leave happens to span (Feb–Apr and Jul–Sep must cost the same).
    public const MATERNITY_DAYS      = 90;
    public const MATERNITY_PAID_DAYS = 60; // first 2 months paid; the 3rd is unpaid
    public const PATERNITY_DAYS      = 7;  // 1 week, fully paid

    // ── Gender values (users.gender) ─────────────────────────────────────────
    public const GENDER_MALE   = 'male';
    public const GENDER_FEMALE = 'female';

    // ── Rule constants ───────────────────────────────────────────────────────
    // Casual and Additional share every restriction below except the
    // probation cap, which the policy only ever mentions for Casual.
    public const CASUAL_MAX_CONSECUTIVE   = 2;
    public const CASUAL_GAP_WORKING_DAYS  = 2;
    public const CASUAL_PROBATION_MAX     = 3;
    public const ANNUAL_NOTICE_DAYS       = 7;
    public const ANNUAL_SPLIT_GAP_DAYS    = 7;

    public const BIM_TEAM            = 'BIM Team';
    public const GBB_TEAM            = 'GBB Team'; // same entitlements as BIM (Additional pool, 18 Casual)
    public const OUTSOURCE_TEAM      = 'outsource department';
    public const OUTSOURCE_TYPE      = 'outsource';
    public const INTERNEE_TYPE       = 'internee';

    /** How far either side of a request we look for neighbouring leave. */
    private const NEIGHBOUR_WINDOW_DAYS = 60;

    public function __construct(private User $user)
    {
    }

    public static function for(User $user): self
    {
        return new self($user);
    }

    public function user(): User
    {
        return $this->user;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Classification
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Any historic or inbound leave_type collapsed onto a canonical type.
     *
     * Unrecognised free text ("Personal", "Vacation", …) keeps falling through
     * to casual exactly as it always has, so no existing balance shifts.
     */
    public static function normalizeType(?string $raw): string
    {
        $type = strtolower(trim((string) $raw));

        return match (true) {
            $type === self::ANNUAL                          => self::ANNUAL,
            $type === self::ADDITIONAL                      => self::ADDITIONAL,
            $type === self::SICK || $type === 'medical leave' => self::SICK,
            $type === self::MARRIAGE                        => self::MARRIAGE,
            $type === self::MATERNITY                       => self::MATERNITY,
            $type === self::PATERNITY                       => self::PATERNITY,
            $type === self::UNPAID                          => self::UNPAID,
            default                                         => self::CASUAL,
        };
    }

    /**
     * A historic 'additional' row entered directly by an admin (reason "added
     * by admin" / "added from admin", case-insensitive) rather than through any
     * app feature — a handful of one-off manual credits from mid-2026, before
     * the "record on behalf of" feature existed.
     *
     * These stay in the database untouched and still count against the
     * Additional balance exactly like any other approved leave — the days
     * genuinely happened. The ONLY thing this affects is visibility: the
     * employee's controller uses this to hide these specific rows from their
     * own leave list, without changing how many days are used or remaining.
     */
    public static function isAdminAddedAdditional(?string $leaveType, ?string $reason): bool
    {
        if (strtolower(trim((string) $leaveType)) !== self::ADDITIONAL) {
            return false;
        }

        return (bool) preg_match('/added\s+(by|from)\s+admin/i', (string) $reason);
    }

    /**
     * Annual, marriage, maternity, paternity and unpaid consume weekends;
     * everything else does not. Maternity/Paternity are continuous recovery and
     * bonding periods — "3 months" and "1 week" plainly mean calendar time, not
     * 90 or 7 working days.
     */
    public static function countsCalendarDays(string $type): bool
    {
        return in_array(
            $type,
            [self::ANNUAL, self::MARRIAGE, self::MATERNITY, self::PATERNITY, self::UNPAID],
            true
        );
    }

    public static function label(string $type): string
    {
        return match ($type) {
            self::ANNUAL     => 'Annual',
            self::CASUAL     => 'Casual',
            self::ADDITIONAL => 'Additional',
            self::SICK       => 'Sick',
            self::MARRIAGE   => 'Marriage',
            self::MATERNITY  => 'Maternity',
            self::PATERNITY  => 'Paternity',
            self::UNPAID     => 'Unpaid',
            default          => ucfirst($type),
        };
    }

    /** Days this request costs, counted the way its type is counted. */
    public function daysFor(string $type, $start, $end): int
    {
        return self::countsCalendarDays($type)
            ? $this->calendarDaysBetween($start, $end)
            : $this->weekdaysBetween($start, $end);
    }

    /** Inclusive calendar-day span, mirroring weekdaysBetween()'s contract. */
    protected function calendarDaysBetween($start, $end): int
    {
        if (empty($start) || empty($end)) {
            return 0;
        }

        try {
            $from = ($start instanceof Carbon ? $start->copy() : Carbon::parse($start))->startOfDay();
            $to   = ($end instanceof Carbon ? $end->copy() : Carbon::parse($end))->startOfDay();
        } catch (\Throwable $e) {
            return 0;
        }

        if ($to->lt($from)) {
            return 0;
        }

        return (int) abs($from->diffInDays($to)) + 1;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Who the policy applies to
    // ─────────────────────────────────────────────────────────────────────────

    public function isBimTeam(): bool
    {
        $team = trim((string) $this->user->employee_team);

        foreach ([self::BIM_TEAM, self::GBB_TEAM] as $eligible) {
            if (strcasecmp($team, $eligible) === 0) {
                return true;
            }
        }

        return false;
    }

    /** users.gender, lowercased — '' when the column is missing or not yet set. */
    public function gender(): string
    {
        return strtolower(trim((string) ($this->user->gender ?? '')));
    }

    /**
     * Whether a gender-specific type applies to this employee.
     *
     * An employee whose gender is NOT RECORDED passes both checks. Gender was
     * added at the same time as these categories, so every pre-existing employee
     * has NULL — treating that as "ineligible" would hide Maternity from every
     * woman already in the system until HR got round to filling the field in.
     * Being permissive here fails safe: HR still approves each request, whereas
     * an entitlement silently missing from the UI is invisible until someone
     * complains.
     */
    public function appliesToGender(string $type): bool
    {
        $gender = $this->gender();

        if ($gender === '') {
            return true; // not recorded yet — don't hide the entitlement
        }

        return match ($type) {
            self::MATERNITY => $gender === self::GENDER_FEMALE,
            self::PATERNITY => $gender === self::GENDER_MALE,
            default         => true,
        };
    }

    /**
     * Internees and the Outsource Department sit outside this policy — they get
     * no entitlement and cannot file through the portal. Returns the reason to
     * show them, or null when the policy does apply.
     */
    public function exclusionReason(): ?string
    {
        $type = strtolower(trim((string) $this->user->employee_type));
        $team = strtolower(trim((string) $this->user->employee_team));

        if ($type === self::INTERNEE_TYPE) {
            return 'Internees are not covered by the company leave entitlement policy. Please arrange any time off directly with your manager.';
        }

        if ($type === self::OUTSOURCE_TYPE || $team === self::OUTSOURCE_TEAM) {
            return 'The Outsource Department is not covered by the company leave entitlement policy. Please arrange any time off directly with your manager.';
        }

        return null;
    }

    public function isExcluded(): bool
    {
        return $this->exclusionReason() !== null;
    }

    /**
     * Probation ends on users.probation_period_end_date. An employee with no
     * date recorded is treated as having COMPLETED probation (confirmed with
     * the client) — nobody is locked out because of a missing field.
     */
    public function probationEndDate(): ?Carbon
    {
        if (empty($this->user->probation_period_end_date)) {
            return null;
        }

        try {
            return Carbon::parse($this->user->probation_period_end_date)->endOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function isOnProbation(?Carbon $asOf = null): bool
    {
        $end = $this->probationEndDate();
        if (!$end) {
            return false;
        }

        return ($asOf ? $asOf->copy() : Carbon::today())->startOfDay()->lte($end);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Leave cycle
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The leave year containing $reference: joining-date anniversary to the day
     * before the next one, or the July–June fallback when no joining date is
     * recorded. Unchanged from the original controller formula.
     */
    public function cycleFor(?Carbon $reference = null): array
    {
        $reference = ($reference ? $reference->copy() : Carbon::today())->startOfDay();

        if ($this->user->joining_date) {
            try {
                $join     = Carbon::parse($this->user->joining_date);
                $yearDiff = $reference->year - $join->year;
                $start    = $join->copy()->addYears($yearDiff)->startOfDay();
                if ($start->gt($reference)) {
                    $start->subYear();
                }

                return [$start, $start->copy()->addYear()->subDay()->endOfDay()];
            } catch (\Throwable $e) {
                // fall through to the static window
            }
        }

        $year = $reference->month >= 7 ? $reference->year : $reference->year - 1;

        return [
            Carbon::create($year, 7, 1)->startOfDay(),
            Carbon::create($year + 1, 6, 30)->endOfDay(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Balances
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Entitlement for a type, or null when the type is uncapped (unpaid).
     * Additional returns 0 for anyone not on the BIM Team — the concept simply
     * doesn't apply to them (see summary(), which omits it from their response
     * entirely rather than showing a permanently-zero card).
     */
    public function entitlementFor(string $type): ?int
    {
        return match ($type) {
            self::ANNUAL     => self::ANNUAL_DAYS,
            self::CASUAL     => self::CASUAL_DAYS,
            self::ADDITIONAL => $this->isBimTeam() ? self::ADDITIONAL_DAYS : 0,
            self::SICK       => self::SICK_DAYS,
            self::MARRIAGE   => self::MARRIAGE_DAYS,
            self::MATERNITY  => $this->appliesToGender(self::MATERNITY) ? self::MATERNITY_DAYS : 0,
            self::PATERNITY  => $this->appliesToGender(self::PATERNITY) ? self::PATERNITY_DAYS : 0,
            default          => null,
        };
    }

    /**
     * The pool an employee actually sees and is gated against: the raw
     * entitlement, quietly reduced by any admin-added Additional days for that
     * cycle. Not shown as "used" — shrinking the ceiling instead means the
     * employee's own visible history always adds up to their own "used" figure,
     * with no unexplained gap they'd have to be told about.
     */
    public function effectiveEntitlementFor(string $type, ?Carbon $reference = null): ?int
    {
        $total = $this->entitlementFor($type);
        if ($total === null) {
            return null;
        }

        return max(0, $total - $this->adminAddedUsedFor($type, $reference));
    }

    /** Days consumed by admin-added rows — only ever relevant to Additional. */
    private function adminAddedUsedFor(string $type, ?Carbon $reference = null): int
    {
        if ($type !== self::ADDITIONAL) {
            return 0;
        }

        [$cycleStart, $cycleEnd] = $this->cycleFor($reference);

        $query = LeaveRequest::where('user_id', $this->user->id)
            ->where('status', '!=', 'Rejected')
            ->whereBetween('start_date', [$cycleStart, $cycleEnd]);

        $used = 0;
        foreach ($query->get(['leave_type', 'start_date', 'end_date', 'reason']) as $row) {
            if (!self::isAdminAddedAdditional($row->leave_type, $row->reason)) {
                continue;
            }
            $used += $this->daysFor(self::ADDITIONAL, $row->start_date, $row->end_date);
        }

        return $used;
    }

    /**
     * Days already committed to a type, counting only what the employee can
     * actually see in their own list. Everything except marriage is scoped to
     * the leave year containing $reference; marriage is a lifetime tally because
     * the entitlement is once per employment.
     *
     * Admin-added Additional rows are deliberately excluded here — they still
     * consume real capacity (effectiveEntitlementFor() shrinks the ceiling to
     * account for them), they just never show up as "used" against it.
     */
    public function usedFor(string $type, ?Carbon $reference = null, ?int $excludeId = null): int
    {
        $query = LeaveRequest::where('user_id', $this->user->id)
            ->where('status', '!=', 'Rejected');

        if ($type !== self::MARRIAGE) {
            [$cycleStart, $cycleEnd] = $this->cycleFor($reference);
            $query->whereBetween('start_date', [$cycleStart, $cycleEnd]);
        }

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $used = 0;
        foreach ($query->get(['leave_type', 'start_date', 'end_date', 'reason']) as $row) {
            if (self::isAdminAddedAdditional($row->leave_type, $row->reason)) {
                continue;
            }
            if (self::normalizeType($row->leave_type) !== $type) {
                continue;
            }
            $used += $this->daysFor($type, $row->start_date, $row->end_date);
        }

        return $used;
    }

    /** Casual days used inside the probation window (its own 3-day allowance). */
    public function casualUsedDuringProbation(?int $excludeId = null): int
    {
        $end = $this->probationEndDate();
        if (!$end) {
            return 0;
        }

        $start = $this->user->joining_date
            ? Carbon::parse($this->user->joining_date)->startOfDay()
            : $end->copy()->subMonths(3)->startOfDay();

        $query = LeaveRequest::where('user_id', $this->user->id)
            ->where('status', '!=', 'Rejected')
            ->whereDate('start_date', '>=', $start->toDateString())
            ->whereDate('start_date', '<=', $end->toDateString());

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $used = 0;
        foreach ($query->get(['leave_type', 'start_date', 'end_date']) as $row) {
            if (self::normalizeType($row->leave_type) !== self::CASUAL) {
                continue;
            }
            $used += $this->daysFor(self::CASUAL, $row->start_date, $row->end_date);
        }

        return $used;
    }

    /**
     * Everything a client needs to render balances and gate its own UI.
     *
     * Returned under a NEW key by both controllers, so older app builds that
     * don't know about it are completely unaffected.
     */
    public function summary(?Carbon $reference = null): array
    {
        $reference = $reference ? $reference->copy() : Carbon::today();
        [$cycleStart, $cycleEnd] = $this->cycleFor($reference);

        $onProbation   = $this->isOnProbation($reference);
        $probationEnd  = $this->probationEndDate();
        $excluded      = $this->exclusionReason();
        $isBim         = $this->isBimTeam();

        $entitlements = [];
        foreach (self::TYPES as $type) {
            // Additional isn't a concept that applies outside the BIM Team —
            // omit it entirely rather than showing a permanently-zero card.
            if ($type === self::ADDITIONAL && !$isBim) {
                continue;
            }

            // Same treatment for the gender-specific categories: a man should
            // not see a permanently-zero Maternity card, and vice versa. An
            // employee with no gender recorded sees BOTH (see appliesToGender).
            if (in_array($type, [self::MATERNITY, self::PATERNITY], true) && !$this->appliesToGender($type)) {
                continue;
            }

            $total = $this->effectiveEntitlementFor($type, $reference);
            $used  = $excluded ? 0 : $this->usedFor($type, $reference);

            $available = true;
            $note      = null;

            if ($excluded) {
                $available = false;
                $note      = $excluded;
            } elseif ($type === self::ANNUAL && $onProbation) {
                $available = false;
                $note      = 'Available once your probation period is complete'
                    . ($probationEnd ? ' on ' . $probationEnd->toDateString() : '') . '.';
            } elseif ($type === self::CASUAL && $onProbation) {
                $note = 'During probation a maximum of ' . self::CASUAL_PROBATION_MAX
                    . ' casual days may be used (' . $this->casualUsedDuringProbation() . ' used so far).';
            } elseif ($type === self::MATERNITY) {
                $note = 'The first ' . self::MATERNITY_PAID_DAYS . ' days are fully paid. '
                    . 'You may return to work after those, or extend to the full '
                    . self::MATERNITY_DAYS . ' days with the remainder unpaid. '
                    . 'Please inform and coordinate with HR and management in advance.';
            } elseif ($type === self::PATERNITY) {
                $note = 'One week, fully paid, following the birth of your child. '
                    . 'Please notify HR and management in advance wherever reasonably possible.';
            }

            $entitlement = [
                'key'       => $type,
                'label'     => self::label($type),
                'total'     => $total,
                'used'      => $used,
                'remaining' => $total === null ? null : max(0, $total - $used),
                'unit'      => self::countsCalendarDays($type) ? 'calendar_days' : 'working_days',
                'scope'     => $type === self::MARRIAGE ? 'employment' : 'leave_year',
                'available' => $available,
                'note'      => $note,
            ];

            // Additive, maternity-only: lets a client show the paid/unpaid split
            // without hard-coding the numbers. No pay logic is applied anywhere.
            if ($type === self::MATERNITY) {
                $entitlement['paid_days']   = self::MATERNITY_PAID_DAYS;
                $entitlement['unpaid_days'] = max(0, self::MATERNITY_DAYS - self::MATERNITY_PAID_DAYS);
            }

            $entitlements[$type] = $entitlement;
        }

        return [
            'can_apply'          => $excluded === null,
            'restricted_reason'  => $excluded,
            'is_bim_team'        => $isBim,
            'on_probation'       => $onProbation,
            'probation_end_date' => $probationEnd?->toDateString(),
            'cycle'              => [
                'start' => $cycleStart->toDateString(),
                'end'   => $cycleEnd->toDateString(),
            ],
            'entitlements'       => $entitlements,
            'rules'              => [
                'casual_max_consecutive'  => self::CASUAL_MAX_CONSECUTIVE,
                'casual_gap_working_days' => self::CASUAL_GAP_WORKING_DAYS,
                'casual_probation_max'    => self::CASUAL_PROBATION_MAX,
                'annual_notice_days'      => self::ANNUAL_NOTICE_DAYS,
                'annual_split_gap_days'   => self::ANNUAL_SPLIT_GAP_DAYS,
                'annual_counts_weekends'  => true,
                'marriage_once_per_employment' => true,
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Validation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Validate a would-be request. Returns null when it may proceed, or the
     * message to show the employee.
     *
     * $excludeId lets an edit ignore the row being edited.
     */
    public function validate(string $type, Carbon $start, Carbon $end, ?int $excludeId = null): ?string
    {
        if ($reason = $this->exclusionReason()) {
            return $reason;
        }

        $start = $start->copy()->startOfDay();
        $end   = $end->copy()->startOfDay();

        $days = $this->daysFor($type, $start, $end);
        if ($days < 1) {
            return self::countsCalendarDays($type)
                ? 'The selected dates do not contain any leave days.'
                : 'The selected dates contain no working days — ' . self::label($type)
                    . ' Leave is counted in working days only.';
        }

        return match ($type) {
            self::ANNUAL     => $this->validateAnnual($start, $end, $days, $excludeId),
            self::CASUAL     => $this->validateCasual($start, $end, $days, $excludeId),
            self::ADDITIONAL => $this->validateAdditional($start, $end, $days, $excludeId),
            self::SICK       => $this->validateSick($start, $end, $days, $excludeId),
            self::MARRIAGE   => $this->validateMarriage($days, $excludeId),
            self::MATERNITY  => $this->validateParentalLeave(self::MATERNITY, $start, $days, $excludeId),
            self::PATERNITY  => $this->validateParentalLeave(self::PATERNITY, $start, $days, $excludeId),
            self::UNPAID     => null, // uncapped fallback, no restrictions
            default          => 'Unsupported leave type.',
        };
    }

    private function validateAnnual(Carbon $start, Carbon $end, int $days, ?int $excludeId): ?string
    {
        if ($this->isOnProbation($start)) {
            $probationEnd = $this->probationEndDate();

            return 'Annual Leave cannot be taken during your probation period'
                . ($probationEnd ? ', which ends on ' . $probationEnd->toDateString() : '') . '.';
        }

        $notice = $this->noticeDaysFor($start);
        if ($notice < self::ANNUAL_NOTICE_DAYS) {
            return 'Annual Leave must be requested at least ' . self::ANNUAL_NOTICE_DAYS
                . ' days in advance. Your start date is ' . max(0, $notice)
                . ' day(s) away. If this is urgent, please ask HR or management to record it for you.';
        }

        if ($message = $this->checkBalance(self::ANNUAL, $days, $start, $excludeId)) {
            return $message;
        }

        // Two annual blocks in one cycle must be 7 full calendar days apart, and
        // the employee has to actually return to work in between.
        [$cycleStart, $cycleEnd] = $this->cycleFor($start);
        $others = LeaveRequest::where('user_id', $this->user->id)
            ->where('status', '!=', 'Rejected')
            ->whereBetween('start_date', [$cycleStart, $cycleEnd])
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get(['id', 'leave_type', 'start_date', 'end_date']);

        foreach ($others as $row) {
            if (self::normalizeType($row->leave_type) !== self::ANNUAL) {
                continue;
            }

            $otherStart = Carbon::parse($row->start_date)->startOfDay();
            $otherEnd   = Carbon::parse($row->end_date)->startOfDay();

            $gap = $otherEnd->lt($start)
                ? $this->calendarDaysStrictlyBetween($otherEnd, $start)
                : ($end->lt($otherStart) ? $this->calendarDaysStrictlyBetween($end, $otherStart) : 0);

            if ($gap < self::ANNUAL_SPLIT_GAP_DAYS) {
                return 'Annual Leave blocks must be separated by at least '
                    . self::ANNUAL_SPLIT_GAP_DAYS . ' full calendar days. Your existing Annual Leave from '
                    . $otherStart->toDateString() . ' to ' . $otherEnd->toDateString()
                    . ' leaves a gap of only ' . $gap . ' day(s).';
            }
        }

        if ($conflict = $this->chainConflict($start, $end, [self::CASUAL, self::ADDITIONAL, self::SICK], $excludeId)) {
            return 'Annual Leave cannot run directly into ' . self::label(self::normalizeType($conflict->leave_type))
                . ' Leave (' . Carbon::parse($conflict->start_date)->toDateString() . ' to '
                . Carbon::parse($conflict->end_date)->toDateString()
                . '). Please leave at least one working day between them.';
        }

        return null;
    }

    private function validateCasual(Carbon $start, Carbon $end, int $days, ?int $excludeId): ?string
    {
        // The probation cap is Casual-specific — the policy never mentions it
        // for Additional, which is BIM-only public-holiday compensation, not
        // tied to tenure.
        if ($this->isOnProbation($start)) {
            $usedInProbation = $this->casualUsedDuringProbation($excludeId);
            if (($usedInProbation + $days) > self::CASUAL_PROBATION_MAX) {
                return 'During probation you may use a maximum of ' . self::CASUAL_PROBATION_MAX
                    . ' Casual Leave days. Used: ' . $usedInProbation . ', remaining: '
                    . max(0, self::CASUAL_PROBATION_MAX - $usedInProbation) . '.';
            }
        }

        return $this->validateCasualStyle(self::CASUAL, $start, $end, $days, $excludeId);
    }

    private function validateAdditional(Carbon $start, Carbon $end, int $days, ?int $excludeId): ?string
    {
        if (!$this->isBimTeam()) {
            return 'Additional Casual Leave (public-holiday compensation) is only available to the BIM and GBB Teams.';
        }

        return $this->validateCasualStyle(self::ADDITIONAL, $start, $end, $days, $excludeId);
    }

    /**
     * The restrictions Casual and Additional share: max 2 consecutive days, 2
     * real working days between blocks of the SAME type, may not run into
     * Annual — each checked against $type's own independent balance.
     */
    private function validateCasualStyle(string $type, Carbon $start, Carbon $end, int $days, ?int $excludeId): ?string
    {
        if ($days > self::CASUAL_MAX_CONSECUTIVE) {
            return self::label($type) . ' Leave cannot be taken for more than ' . self::CASUAL_MAX_CONSECUTIVE
                . ' consecutive working days.';
        }

        if ($message = $this->checkBalance($type, $days, $start, $excludeId)) {
            return $message;
        }

        // A new block needs 2 real working days after the previous block OF
        // THE SAME TYPE. Weekends and days already covered by other leave do
        // not count. Casual and Additional are independent here — a Casual
        // block does not block a following Additional block, or vice versa.
        $neighbours = $this->neighbouringLeaves($start, $end, $excludeId);
        foreach ($neighbours as $row) {
            if (self::normalizeType($row->leave_type) !== $type) {
                continue;
            }

            $otherStart = Carbon::parse($row->start_date)->startOfDay();
            $otherEnd   = Carbon::parse($row->end_date)->startOfDay();

            $others = $neighbours->reject(fn ($r) => (int) $r->id === (int) $row->id);

            if ($otherEnd->lt($start)) {
                $free = $this->freeWorkingDaysBetween($otherEnd, $start, $others);
            } elseif ($end->lt($otherStart)) {
                $free = $this->freeWorkingDaysBetween($end, $otherStart, $others);
            } else {
                $free = 0; // overlapping blocks
            }

            if ($free < self::CASUAL_GAP_WORKING_DAYS) {
                return 'You must work at least ' . self::CASUAL_GAP_WORKING_DAYS
                    . ' full working days between ' . self::label($type) . ' Leave blocks. Your '
                    . self::label($type) . ' Leave from '
                    . $otherStart->toDateString() . ' to ' . $otherEnd->toDateString()
                    . ' leaves only ' . $free . ' working day(s) in between (weekends and other leave do not count).';
            }
        }

        if ($conflict = $this->chainConflict($start, $end, [self::ANNUAL], $excludeId)) {
            return self::label($type) . ' Leave cannot be taken directly before or after Annual Leave ('
                . Carbon::parse($conflict->start_date)->toDateString() . ' to '
                . Carbon::parse($conflict->end_date)->toDateString()
                . '). Please leave at least one working day between them.';
        }

        return null;
    }

    private function validateSick(Carbon $start, Carbon $end, int $days, ?int $excludeId): ?string
    {
        if ($message = $this->checkBalance(self::SICK, $days, $start, $excludeId)) {
            return $message;
        }

        if ($conflict = $this->chainConflict($start, $end, [self::ANNUAL], $excludeId)) {
            return 'Sick Leave cannot be taken directly before or after Annual Leave ('
                . Carbon::parse($conflict->start_date)->toDateString() . ' to '
                . Carbon::parse($conflict->end_date)->toDateString()
                . '). If you are genuinely unwell, please contact HR so it can be reviewed.';
        }

        return null;
    }

    private function validateMarriage(int $days, ?int $excludeId): ?string
    {
        $used      = $this->usedFor(self::MARRIAGE, null, $excludeId);
        $remaining = max(0, self::MARRIAGE_DAYS - $used);

        if (($used + $days) > self::MARRIAGE_DAYS) {
            return 'Marriage Leave is ' . self::MARRIAGE_DAYS
                . ' paid calendar days, once per employment. Used: ' . $used . ', remaining: '
                . $remaining . '. Any days beyond that should be submitted as Unpaid Leave.';
        }

        return null;
    }

    /**
     * Maternity and Paternity share one shape: a gender check, then a plain
     * balance check against their own pool.
     *
     * Deliberately NO adjacency/gap rules. Unlike Casual or Annual, these are a
     * single continuous block around a birth — running into another leave type
     * is normal and not something to block. They are also administered
     * separately from Annual/Casual/Sick, so they never touch those balances.
     */
    private function validateParentalLeave(string $type, Carbon $start, int $days, ?int $excludeId): ?string
    {
        if (!$this->appliesToGender($type)) {
            return self::label($type) . ' Leave is not available for your recorded gender. '
                . 'If this is wrong, please ask HR to correct it on your profile.';
        }

        if ($message = $this->checkBalance($type, $days, $start, $excludeId)) {
            // The balance message is generic; for maternity the useful next step
            // is specifically "the rest is unpaid", so say that instead.
            if ($type === self::MATERNITY) {
                $used = $this->usedFor($type, $start, $excludeId);

                return 'Maternity Leave is ' . self::MATERNITY_DAYS . ' calendar days ('
                    . self::MATERNITY_PAID_DAYS . ' paid, the remainder unpaid). Used: ' . $used
                    . ', remaining: ' . max(0, self::MATERNITY_DAYS - $used) . ', requested: ' . $days
                    . '. Anything beyond that should be submitted as Unpaid Leave.';
            }

            return $message;
        }

        return null;
    }

    /** Shared "have you got the days?" check. Uses the effective (shrunk) ceiling. */
    private function checkBalance(string $type, int $days, Carbon $reference, ?int $excludeId): ?string
    {
        $total = $this->effectiveEntitlementFor($type, $reference);
        if ($total === null) {
            return null;
        }

        $used = $this->usedFor($type, $reference, $excludeId);
        if (($used + $days) <= $total) {
            return null;
        }

        return 'You have exceeded your ' . self::label($type) . ' Leave allowance for this leave year. '
            . 'Entitlement: ' . $total . ', used: ' . $used . ', remaining: ' . max(0, $total - $used)
            . ', requested: ' . $days . '.';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Date helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Whole days between today and the start date (negative when in the past). */
    private function noticeDaysFor(Carbon $start): int
    {
        $today = Carbon::today()->startOfDay();
        $from  = $start->copy()->startOfDay();

        return $from->gte($today) ? (int) abs($today->diffInDays($from)) : -1;
    }

    /** Calendar days strictly between two blocks (neither end included). */
    private function calendarDaysStrictlyBetween(Carbon $endA, Carbon $startB): int
    {
        $from = $endA->copy()->addDay()->startOfDay();
        $to   = $startB->copy()->subDay()->startOfDay();

        return $to->lt($from) ? 0 : $this->calendarDaysBetween($from, $to);
    }

    /** Working days strictly between two blocks (neither end included). */
    private function workingDaysStrictlyBetween(Carbon $endA, Carbon $startB): int
    {
        $from = $endA->copy()->addDay()->startOfDay();
        $to   = $startB->copy()->subDay()->startOfDay();

        return $to->lt($from) ? 0 : $this->weekdaysBetween($from, $to);
    }

    /**
     * Working days between two blocks that the employee could actually have
     * worked — weekends excluded, and days already covered by other leave
     * removed (the policy is explicit that those do not count).
     */
    private function freeWorkingDaysBetween(Carbon $endA, Carbon $startB, $otherLeaves): int
    {
        $from = $endA->copy()->addDay()->startOfDay();
        $to   = $startB->copy()->subDay()->startOfDay();

        if ($to->lt($from)) {
            return 0;
        }

        $raw = $this->weekdaysBetween($from, $to);
        // Comfortably far apart — no need to walk the range day by day.
        if ($raw === 0 || $raw > 30) {
            return $raw;
        }

        $ranges = [];
        foreach ($otherLeaves as $row) {
            $ranges[] = [
                Carbon::parse($row->start_date)->startOfDay(),
                Carbon::parse($row->end_date)->startOfDay(),
            ];
        }

        $free = 0;
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }

            $covered = false;
            foreach ($ranges as [$rangeStart, $rangeEnd]) {
                if ($day->gte($rangeStart) && $day->lte($rangeEnd)) {
                    $covered = true;
                    break;
                }
            }

            if (!$covered) {
                $free++;
            }
        }

        return $free;
    }

    /** Non-rejected leave overlapping a window either side of the request. */
    private function neighbouringLeaves(Carbon $start, Carbon $end, ?int $excludeId)
    {
        $windowStart = $start->copy()->subDays(self::NEIGHBOUR_WINDOW_DAYS)->toDateString();
        $windowEnd   = $end->copy()->addDays(self::NEIGHBOUR_WINDOW_DAYS)->toDateString();

        return LeaveRequest::where('user_id', $this->user->id)
            ->where('status', '!=', 'Rejected')
            ->whereDate('start_date', '<=', $windowEnd)
            ->whereDate('end_date', '>=', $windowStart)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->get(['id', 'leave_type', 'start_date', 'end_date']);
    }

    /**
     * The first leave of one of $conflictTypes sitting in the same unbroken run
     * of absence as the requested block — directly adjacent, or bridged by other
     * leave with no real working day anywhere in between.
     *
     * This is what enforces "Annual → Casual → Sick" and friends, not just the
     * simple two-block case.
     */
    private function chainConflict(Carbon $start, Carbon $end, array $conflictTypes, ?int $excludeId): ?LeaveRequest
    {
        $rows = $this->neighbouringLeaves($start, $end, $excludeId);
        if ($rows->isEmpty()) {
            return null;
        }

        $blocks = [];
        foreach ($rows as $row) {
            $blocks[] = [
                'start' => Carbon::parse($row->start_date)->startOfDay(),
                'end'   => Carbon::parse($row->end_date)->startOfDay(),
                'row'   => $row,
            ];
        }
        $blocks[] = ['start' => $start->copy(), 'end' => $end->copy(), 'row' => null];

        usort($blocks, fn ($a, $b) => $a['start']->getTimestamp() <=> $b['start']->getTimestamp());

        // Walk the blocks in date order, breaking them into chains wherever a
        // real working day separates one from the next.
        $chains     = [];
        $current    = [];
        $currentEnd = null;

        foreach ($blocks as $block) {
            if ($current === []) {
                $current    = [$block];
                $currentEnd = $block['end']->copy();
                continue;
            }

            $contiguous = $block['start']->lte($currentEnd)
                || $this->workingDaysStrictlyBetween($currentEnd, $block['start']) === 0;

            if ($contiguous) {
                $current[] = $block;
                if ($block['end']->gt($currentEnd)) {
                    $currentEnd = $block['end']->copy();
                }
            } else {
                $chains[]   = $current;
                $current    = [$block];
                $currentEnd = $block['end']->copy();
            }
        }

        if ($current !== []) {
            $chains[] = $current;
        }

        foreach ($chains as $chain) {
            $containsRequest = false;
            foreach ($chain as $block) {
                if ($block['row'] === null) {
                    $containsRequest = true;
                    break;
                }
            }

            if (!$containsRequest) {
                continue;
            }

            foreach ($chain as $block) {
                if ($block['row'] === null) {
                    continue;
                }
                if (in_array(self::normalizeType($block['row']->leave_type), $conflictTypes, true)) {
                    return $block['row'];
                }
            }
        }

        return null;
    }
}
