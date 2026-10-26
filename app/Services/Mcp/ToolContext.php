<?php

namespace App\Services\Mcp;

use App\Models\User;
use Carbon\Carbon;

/** What a tool needs while it runs: who is asking and how to reach the portal as them. */
final class ToolContext
{
    public InternalApi $api;

    public function __construct(public User $user, public string $ip = '', public ?string $clientId = null)
    {
        $this->api = new InternalApi($user);
    }

    public function today(): string
    {
        return Carbon::now()->toDateString();
    }

    /** Portal "limit" arguments, bounded. */
    public function limit(array $args, int $default = 25, int $max = 100): int
    {
        return max(1, min($max, (int) ($args['limit'] ?? $default)));
    }
}
