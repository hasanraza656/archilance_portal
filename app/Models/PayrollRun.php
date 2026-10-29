<?php

namespace App\Models;

use App\Support\PayrollSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * One month's payroll batch — the unit that gets approved.
 *
 * Lifecycle:
 *   Draft → Pending Approval → Approved → Paid → Closed        (Cancelled from any unpaid state)
 *
 * Status gates the whole module: payslips are freely editable while the run is
 * Draft or Pending Approval, payable once it is Approved, and frozen for good
 * when the month is Closed (an admin can reopen a closed month, which is
 * audit-logged). Those predicates live here so every controller action asks the
 * same question.
 */
class PayrollRun extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'period_month'     => 'date:Y-m-d',
        'approved_at'      => 'datetime',
        'paid_at'          => 'datetime',
        'closed_at'        => 'datetime',
        'total_gross'      => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'total_net'        => 'decimal:2',
    ];

    public const DRAFT     = 'Draft';
    public const PENDING   = 'Pending Approval';
    public const APPROVED  = 'Approved';
    public const PAID      = 'Paid';
    public const CLOSED    = 'Closed';
    public const CANCELLED = 'Cancelled';

    public function payslips()
    {
        return $this->hasMany(Payslip::class, 'payroll_run_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Payslips may be added, edited or removed freely. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::PENDING], true);
    }

    /** Money may actually be marked as sent. */
    public function isPayable(): bool
    {
        return in_array($this->status, [self::APPROVED, self::PAID], true);
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }

    /**
     * Bonuses / deductions / notes may still be added to payslips that are not
     * paid yet. Open until the month is closed (or the run cancelled), so a late
     * bonus decision doesn't force reopening an approved run.
     */
    public function canAdjust(): bool
    {
        return in_array($this->status, [self::DRAFT, self::PENDING, self::APPROVED, self::PAID], true);
    }

    /**
     * Recomputes the denormalised totals from the payslips, keeps the run's
     * status honest, and returns $this.
     *
     *  - Approved + nobody left to pay        → Paid  (held / skipped slips don't block it)
     *  - Paid + someone is awaiting payment   → Approved again (a payment was reversed,
     *    or a held / skipped slip was released)
     */
    public function recalculate(bool $save = true): self
    {
        $slips = $this->payslips()->get(['status', 'currency', 'gross_amount', 'total_deductions', 'net_amount']);

        $counted = $slips->whereNotIn('status', ['Skipped']);

        // The money totals are in the run's own currency (PKR). Slips in another currency (e.g. USD salaries) are
        // counted as people but never added into the PKR figures; attachMoney() reports them separately.
        $runCurrency = $this->currency ?: 'PKR';
        $base = $counted->filter(fn ($s) => ($s->currency ?: 'PKR') === $runCurrency);

        $this->employee_count    = $counted->count();
        $this->total_gross       = (float) $base->sum('gross_amount');
        $this->total_deductions  = (float) $base->sum('total_deductions');
        $this->total_net         = (float) $base->sum('net_amount');
        $this->paid_count        = $counted->where('status', 'Paid')->count();

        $awaiting = $counted->whereNotIn('status', ['Paid', 'On Hold'])->count();

        if ($this->status === self::APPROVED && $this->paid_count > 0 && $awaiting === 0) {
            $this->status = self::PAID;
            if (PayrollSchema::has('payroll_runs', 'paid_at')) {
                $this->paid_at = now();
            }
        } elseif ($this->status === self::PAID && $awaiting > 0) {
            $this->status = self::APPROVED;
            if (PayrollSchema::has('payroll_runs', 'paid_at')) {
                $this->paid_at = null;
            }
        }

        if ($save) {
            $this->save();
        }

        return $this;
    }

    /**
     * Attaches paid / outstanding / held amounts to each run (one grouped query)
     * so lists and headers can show real money progress, not just counts.
     *
     * @param  iterable<PayrollRun>  $runs
     */
    public static function attachMoney(iterable $runs): void
    {
        $ids = collect($runs)->pluck('id')->all();
        if (empty($ids)) {
            return;
        }

        $rows = Payslip::query()
            ->whereIn('payroll_run_id', $ids)
            ->selectRaw(
                "payroll_run_id,
                 currency,
                 SUM(CASE WHEN status = 'Paid' THEN net_amount ELSE 0 END) AS paid_amount,
                 SUM(CASE WHEN status IN ('Pending','Approved') THEN net_amount ELSE 0 END) AS outstanding_amount,
                 SUM(CASE WHEN status NOT IN ('Skipped') THEN net_amount ELSE 0 END) AS net_amount_sum,
                 SUM(CASE WHEN status NOT IN ('Skipped') THEN 1 ELSE 0 END) AS slip_count,
                 SUM(CASE WHEN status = 'On Hold' THEN net_amount ELSE 0 END) AS held_amount,
                 SUM(CASE WHEN status = 'On Hold' THEN 1 ELSE 0 END) AS held_count,
                 SUM(CASE WHEN status = 'Skipped' THEN 1 ELSE 0 END) AS skipped_count"
            )
            ->groupBy('payroll_run_id', 'currency')
            ->get()
            ->groupBy('payroll_run_id');

        foreach ($runs as $run) {
            $runCurrency = $run->currency ?: 'PKR';
            $paid = $outstanding = $held = 0.0;
            $heldCount = $skipped = 0;
            $other = [];

            foreach ($rows->get($run->id, collect()) as $r) {
                $cur = $r->currency ?: 'PKR';
                $heldCount += (int) $r->held_count;
                $skipped += (int) $r->skipped_count;

                if ($cur === $runCurrency) {
                    $paid += (float) $r->paid_amount;
                    $outstanding += (float) $r->outstanding_amount;
                    $held += (float) $r->held_amount;
                } elseif ((int) $r->slip_count > 0) {
                    $other[$cur] = [
                        'currency'    => $cur,
                        'count'       => (int) $r->slip_count,
                        'net'         => round((float) $r->net_amount_sum, 2),
                        'paid'        => round((float) $r->paid_amount, 2),
                        'outstanding' => round((float) $r->outstanding_amount, 2),
                    ];
                }
            }

            $run->setAttribute('paid_amount', round($paid, 2));
            $run->setAttribute('outstanding_amount', round($outstanding, 2));
            $run->setAttribute('held_amount', round($held, 2));
            $run->setAttribute('held_count', $heldCount);
            $run->setAttribute('skipped_count', $skipped);
            $run->setAttribute('other_currencies', array_values($other));
        }
    }
}
