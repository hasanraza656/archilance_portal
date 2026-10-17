<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A saved organization chart: the full node snapshot plus its changes-vs-database report. */
class OrgChart extends Model
{
    protected $guarded = [];

    protected $casts = [
        'snapshot' => 'array',
        'changes' => 'array',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
