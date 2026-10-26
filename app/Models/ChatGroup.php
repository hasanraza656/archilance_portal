<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatGroup extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function members()
    {
        return $this->hasMany(ChatGroupMember::class, 'group_id');
    }

    public function activeMembers()
    {
        return $this->hasMany(ChatGroupMember::class, 'group_id')->whereNull('left_at');
    }
}
