<?php

/*
|--------------------------------------------------------------------------
| MCP server (Claude connector for portal admins)
|--------------------------------------------------------------------------
| Everything is OFF until MCP_ENABLED=true is set in .env. After editing .env on a server that caches its config,
| open /clear-cache once so the new values are picked up.
*/

$list = fn ($value) => array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($v) => $v !== ''));

return [
    // Master switch. false = every /mcp and /oauth/* URL answers 404 and nothing else changes.
    'enabled' => filter_var(env('MCP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    // true = Claude can only READ. Every tool that creates, changes or deletes anything is hidden and refused.
    'read_only' => filter_var(env('MCP_READ_ONLY', false), FILTER_VALIDATE_BOOLEAN),

    // Optional allow-list of admin e-mail addresses that may connect (comma separated). Empty = every admin (role 2).
    'allowed_admin_emails' => array_map('strtolower', $list(env('MCP_ALLOWED_ADMIN_EMAILS', ''))),

    // Hosts Claude may be sent back to after login. localhost is for Claude Desktop / Claude Code.
    'allowed_redirect_hosts' => array_values(array_unique(array_merge(
        ['claude.ai', 'claude.com', 'localhost', '127.0.0.1', '[::1]'],
        $list(env('MCP_ALLOWED_REDIRECT_HOSTS', ''))
    ))),

    // Optional: force the public address used in the metadata (e.g. https://backend.archilance.org).
    // Normally NOT needed - it is taken from the request.
    'public_url' => rtrim((string) env('MCP_PUBLIC_URL', ''), '/'),

    'access_ttl_minutes' => max(5, (int) env('MCP_ACCESS_TTL_MINUTES', 60)),
    'refresh_ttl_days' => max(1, (int) env('MCP_REFRESH_TTL_DAYS', 30)),

    // Calls per minute per connection.
    'rate_limit_per_minute' => max(10, (int) env('MCP_RATE_LIMIT', 120)),
];
