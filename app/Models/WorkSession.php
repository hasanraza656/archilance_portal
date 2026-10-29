<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkSession extends Model
{
     protected $guarded = [];

     // The OneDrive path of a manual-time attachment is never sent to the browser; it gets `has_proof` instead.
     protected $hidden = ['proof_path'];
     protected $appends = ['has_proof'];

     public function getHasProofAttribute(): bool
     {
          return !empty($this->attributes['proof_path'] ?? null);
     }

     public function screenshots()
     {
          return $this->hasMany(Screenshot::class, 'session_id');
     }

     public function userDetail()
     {
        return $this->belongsTo(User::class, 'user_id');
     }

      public function taskDetail()
     {
        return $this->belongsTo(ProjectTask::class, 'task_id');
     }
     
     public function idleTimes()
    {
        return $this->hasMany(SessionTimeAdjustment::class, 'session_id');
    }
}
