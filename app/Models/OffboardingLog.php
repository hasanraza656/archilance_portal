<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OffboardingLog extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['created_at' => 'datetime'];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }
}
