<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One employee's pay for one month.
 *
 * The snapshot columns (employee_name, designation, ...) are authoritative for
 * display: a reprinted payslip must show what was true when it was issued, not
 * what is true now. Only fall back to the live user record when a snapshot is
 * missing (rows created before a field existed).
 */
class Payslip extends Model
{
    protected $guarded = [];

    protected $casts = [
        'period_month'     => 'date:Y-m-d',
        'joining_date'     => 'date:Y-m-d',
        'paid_at'          => 'datetime',
        'basic_salary'     => 'decimal:2',
        'total_earnings'   => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'gross_amount'     => 'decimal:2',
        'net_amount'       => 'decimal:2',
        'pay_notes'        => 'array',
    ];

    public function run()
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function items()
    {
        return $this->hasMany(PayslipItem::class, 'payslip_id');
    }

    /**
     * Re-derives the money columns from the line items. Called after any item
     * change so the stored totals can never drift from the lines that justify
     * them — lists and reports read the columns, never re-aggregate.
     */
    public function recalculate(bool $save = true): self
    {
        $earnings   = (float) $this->items()->where('kind', PayslipItem::EARNING)->sum('amount');
        $deductions = (float) $this->items()->where('kind', PayslipItem::DEDUCTION)->sum('amount');

        $this->total_earnings   = $earnings;
        $this->total_deductions = $deductions;
        $this->gross_amount     = (float) $this->basic_salary + $earnings;
        // Net is floored at zero: deductions larger than gross are a data-entry
        // error, and a negative payslip would corrupt every downstream total.
        $this->net_amount       = max(0, $this->gross_amount - $deductions);

        if ($save) {
            $this->save();
        }

        return $this;
    }
}
