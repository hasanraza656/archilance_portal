<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A standing monthly line for one employee — a fixed allowance, a loan
 * instalment, a tax withholding — added automatically to every payroll that is
 * generated while it is active (start_month <= month <= end_month).
 */
class RecurringPayrollItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'start_month' => 'date:Y-m-d',
        'end_month' => 'date:Y-m-d',
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Items that apply to the payroll month starting $month. */
    public function scopeAppliesTo($query, $month)
    {
        $m = \Carbon\Carbon::parse($month)->startOfMonth()->toDateString();

        return $query->where('is_active', true)
            ->whereDate('start_month', '<=', $m)
            ->where(function ($q) use ($m) {
                $q->whereNull('end_month')->orWhereDate('end_month', '>=', $m);
            });
    }
}
