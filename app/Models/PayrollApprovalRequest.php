<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An executive's request for an admin to approve one payroll run. */
class PayrollApprovalRequest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'decided_at' => 'datetime',
        'total_net' => 'decimal:2',
        'email_ok' => 'boolean',
    ];

    public const PENDING = 'Pending';
    public const APPROVED = 'Approved';
    public const CHANGES = 'Changes Requested';
    public const REJECTED = 'Rejected';
    public const SUPERSEDED = 'Superseded';

    public function run()
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
