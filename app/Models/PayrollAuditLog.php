<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of the payroll audit trail. Append-only: rows are never edited. */
class PayrollAuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
        'amount' => 'float',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
