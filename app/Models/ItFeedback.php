<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One person's feedback for the IT team (or the record that they skipped the popup). */
class ItFeedback extends Model
{
    protected $table = 'it_feedback';

    protected $guarded = [];

    protected $casts = [
        'skipped_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }
}
