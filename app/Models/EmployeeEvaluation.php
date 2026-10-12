<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeEvaluation extends Model
{
    /**
     * Criterion => weight in percent (sums to 100). Same order the tracker asks
     * them in. Keep in sync with EVALUATION_CRITERIA in the v2 frontend
     * (src/api/evaluations.js) and with CRITERIA in the tracker (evaluation.js).
     */
    public const WEIGHTS = [
        'quality' => 30,
        'timely_delivery' => 20,
        'communication' => 15,
        'understanding' => 15,
        'ownership_initiative' => 10,
        'productivity' => 10,
    ];

    public const MAX_SCORE = 10;

    protected $guarded = [];

    protected $casts = [
        'week_start' => 'date:Y-m-d',
        'quality' => 'integer',
        'timely_delivery' => 'integer',
        'communication' => 'integer',
        'understanding' => 'integer',
        'ownership_initiative' => 'integer',
        'productivity' => 'integer',
    ];

    protected $appends = ['overall_percent', 'week_end'];

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    /**
     * Weighted overall score as a percentage (0–100, one decimal): each
     * criterion contributes (score ÷ 10) × its weight, so all tens = 100%.
     */
    public function getOverallPercentAttribute()
    {
        $total = 0.0;

        foreach (self::WEIGHTS as $field => $weight) {
            if ($this->{$field} === null) {
                return null;
            }
            $total += ($this->{$field} / self::MAX_SCORE) * $weight;
        }

        return round($total, 1);
    }

    /** Sunday closing the evaluated week (Y-m-d). */
    public function getWeekEndAttribute()
    {
        return $this->week_start ? $this->week_start->copy()->addDays(6)->toDateString() : null;
    }
}
