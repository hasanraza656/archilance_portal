<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One person's monthly rating of one manager/executive.
 * See App\Support\ManagerEvaluationQuestions for the kinds and questions.
 */
class ManagerEvaluation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'month_start' => 'date:Y-m-d',
        'skipped' => 'boolean',
        'scores' => 'array',
        'average' => 'float',
    ];

    public function rater()
    {
        return $this->belongsTo(User::class, 'rater_id');
    }

    public function ratee()
    {
        return $this->belongsTo(User::class, 'ratee_id');
    }
}
