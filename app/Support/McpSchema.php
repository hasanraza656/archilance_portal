<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Whether the MCP database update has been applied, and the MCP settings.
 *
 * The Laravel files can be uploaded before the SQL runs (System settings -> Update database), so every MCP
 * endpoint asks here first and answers "not available yet" instead of throwing. Nothing else in the portal
 * ever calls this, so the rest of the app behaves exactly as before.
 */
class McpSchema
{
    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Schema::hasTable('mcp_clients')
                    && Schema::hasTable('mcp_auth_codes')
                    && Schema::hasTable('mcp_tokens')
                    && Schema::hasTable('mcp_audit_logs');
            } catch (\Throwable $e) {
                self::$ready = false;
            }
        }

        return self::$ready;
    }

    public static function flush(): void
    {
        self::$ready = null;
    }

    /**
     * Settings from config/mcp.php. If the server has a cached config that predates that file, fall back to
     * reading the file directly, so a missing cache entry can never turn the feature on by accident (the
     * defaults are all "off / safest").
     */
    public static function settings(): array
    {
        $cfg = config('mcp');
        if (!is_array($cfg)) {
            $file = config_path('mcp.php');
            $cfg = is_file($file) ? (array) require $file : [];
        }

        return $cfg;
    }

    public static function enabled(): bool
    {
        return (bool) (self::settings()['enabled'] ?? false);
    }

    public static function readOnly(): bool
    {
        return (bool) (self::settings()['read_only'] ?? false);
    }
}
