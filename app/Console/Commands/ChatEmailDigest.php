<?php

namespace App\Console\Commands;

use App\Services\ChatDigest;
use Illuminate\Console\Command;

/** Sends the "you have unread messages" digest to people who left chats unread - see App\Services\ChatDigest for the rules. */
class ChatEmailDigest extends Command
{
    protected $signature = 'chat:email-digest';

    protected $description = 'Email a single digest to people with chat messages that stayed unread (never one email per message).';

    public function handle(ChatDigest $digest): int
    {
        $sent = $digest->run();
        $this->info("Chat digests sent: {$sent}");

        return self::SUCCESS;
    }
}
