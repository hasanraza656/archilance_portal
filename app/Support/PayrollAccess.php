<?php

namespace App\Support;

use App\Models\Payslip;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Who may see which salaries.
 *
 *  - Admins see everything.
 *  - People listed in PAYROLL_FULL_VIEW_USER_IDS (default: 30, the accountant who prepares and initiates payroll) see
 *    everything too, including the salaries of executives.
 *  - Every other executive sees all ordinary employees' salaries and their own, but NOT another executive's.
 *  - Nobody else reaches payroll at all (the routes are admin / executive only).
 *
 * Applied on the server, to every endpoint that could carry a salary figure, so a hidden salary cannot be read by
 * calling the API directly, and the run totals shown to a restricted person never include a hidden salary.
 */
class PayrollAccess
{
    private static ?array $hidden = null;
    private static ?int $hiddenFor = null;

    public static function viewer(): ?User
    {
        $u = Auth::user();

        return $u instanceof User ? $u : null;
    }

    public static function isAdmin(): bool
    {
        return (int) (self::viewer()->user_role ?? 0) === 2;
    }

    /** @return int[] */
    public static function fullViewIds(): array
    {
        $raw = (string) env('PAYROLL_FULL_VIEW_USER_IDS', '30');

        return array_values(array_filter(array_map('intval', preg_split('/[\s,;]+/', $raw) ?: []), fn ($v) => $v > 0));
    }

    public static function canSeeExecutives(): bool
    {
        $u = self::viewer();
        if (!$u) {
            return false;
        }

        return self::isAdmin() || in_array((int) $u->id, self::fullViewIds(), true);
    }

    /** True when some salaries are hidden from the person asking. */
    public static function restricted(): bool
    {
        return !self::canSeeExecutives();
    }

    /**
     * User ids whose salaries the viewer must not see: every executive (including former ones) except the viewer.
     *
     * @return int[]
     */
    public static function hiddenUserIds(): array
    {
        $me = (int) (self::viewer()->id ?? 0);
        if (self::$hidden !== null && self::$hiddenFor === $me) {
            return self::$hidden;
        }
        self::$hiddenFor = $me;

        if (self::canSeeExecutives()) {
            return self::$hidden = [];
        }

        return self::$hidden = User::withTrashed()
            ->where('user_role', 3)
            ->where('employee_type', 'Executive')
            ->where('id', '!=', $me)
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    public static function isHidden($userId): bool
    {
        return in_array((int) $userId, self::hiddenUserIds(), true);
    }

    /** Abort 403 when the viewer may not see this person's salary. */
    public static function guard($userId): void
    {
        if (self::isHidden($userId)) {
            abort(403, 'You do not have access to this salary.');
        }
    }

    /** Restricts a Payslip (or builder) query to the slips the viewer may see. */
    public static function visibleSlips($query)
    {
        $hidden = self::hiddenUserIds();
        if (!$hidden) {
            return $query;
        }
        $me = (int) (self::viewer()->id ?? 0);

        return $query->whereNotIn($query->getModel()->getTable() . '.user_id', $hidden)
            ->where(function ($w) use ($me) {
                $w->whereNull('payslips.designation')
                    ->orWhere('payslips.designation', '!=', 'Executive')
                    ->orWhere('payslips.user_id', $me);
            });
    }

    /** Is this loaded slip one the viewer may not see? */
    public static function slipHidden(Payslip $slip): bool
    {
        if (!self::hiddenUserIds()) {
            return false;
        }
        if (self::isHidden($slip->user_id)) {
            return true;
        }

        return $slip->designation === 'Executive' && (int) $slip->user_id !== (int) (self::viewer()->id ?? 0);
    }

    /**
     * For a restricted viewer, replaces each run's totals with the totals of the slips they may see, so an executive's
     * salary cannot be worked out from the run total. Adds `restricted => true` so the screens can say so.
     *
     * @param iterable<PayrollRun> $runs
     */
    public static function restrictRuns(iterable $runs): void
    {
        if (!self::hiddenUserIds()) {
            return;
        }
        foreach ($runs as $run) {
            $slips = self::visibleSlips(Payslip::query())->where('payroll_run_id', $run->id)->get(['status', 'currency', 'gross_amount', 'total_deductions', 'net_amount']);
            $counted = $slips->whereNotIn('status', ['Skipped']);
            $base = $counted->filter(fn ($s) => ($s->currency ?: 'PKR') === ($run->currency ?: 'PKR'));
            $run->setAttribute('employee_count', $counted->count());
            $run->setAttribute('total_gross', round((float) $base->sum('gross_amount'), 2));
            $run->setAttribute('total_deductions', round((float) $base->sum('total_deductions'), 2));
            $run->setAttribute('total_net', round((float) $base->sum('net_amount'), 2));
            $run->setAttribute('paid_count', $counted->where('status', 'Paid')->count());
            $run->setAttribute('paid_amount', round((float) $base->where('status', 'Paid')->sum('net_amount'), 2));
            $run->setAttribute('outstanding_amount', round((float) $base->whereIn('status', ['Pending', 'Approved'])->sum('net_amount'), 2));
            $run->setAttribute('held_amount', round((float) $base->where('status', 'On Hold')->sum('net_amount'), 2));
            $run->setAttribute('held_count', $counted->where('status', 'On Hold')->count());
            $other = [];
            foreach ($counted->groupBy(fn ($s) => $s->currency ?: 'PKR') as $cur => $g) {
                if ($cur === ($run->currency ?: 'PKR')) {
                    continue;
                }
                $other[] = [
                    'currency'    => $cur,
                    'count'       => $g->count(),
                    'net'         => round((float) $g->sum('net_amount'), 2),
                    'paid'        => round((float) $g->where('status', 'Paid')->sum('net_amount'), 2),
                    'outstanding' => round((float) $g->whereIn('status', ['Pending', 'Approved'])->sum('net_amount'), 2),
                ];
            }
            $run->setAttribute('other_currencies', $other);
            $run->setAttribute('restricted', true);
        }
    }

    public static function flush(): void
    {
        self::$hidden = null;
        self::$hiddenFor = null;
    }
}
