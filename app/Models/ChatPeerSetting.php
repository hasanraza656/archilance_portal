<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One person's pin / mute choice for one conversation (a user or a group). */
class ChatPeerSetting extends Model
{
    protected $guarded = [];

    protected $casts = [
        'pinned_at' => 'datetime',
        'muted' => 'boolean',
        'muted_until' => 'datetime',
    ];

    /** Muted right now (a timed mute ends by itself). */
    public function isMuted(): bool
    {
        if (!$this->muted) {
            return false;
        }

        return $this->muted_until === null || $this->muted_until->isFuture();
    }
}
