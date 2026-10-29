<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Tools\AdminTools;
use App\Services\Mcp\Tools\ChatTools;
use App\Services\Mcp\Tools\ExperienceLetterTools;
use App\Services\Mcp\Tools\LeaveTools;
use App\Services\Mcp\Tools\PayrollTools;
use App\Services\Mcp\Tools\PeopleTools;
use App\Services\Mcp\Tools\ProjectTools;
use App\Services\Mcp\Tools\TimeTools;

/** Every tool the connector offers, in the order the AI sees them. */
final class Catalog
{
    /** @var array<string,Tool>|null */
    private static ?array $all = null;

    /** @return array<string,Tool> keyed by tool name */
    public static function all(): array
    {
        if (self::$all === null) {
            $admin = AdminTools::all();
            $list = array_merge(
                [$admin[0]],                                          // get_portal_overview first
                ChatTools::all(),
                ProjectTools::all(),
                PeopleTools::all(),
                TimeTools::all(),
                LeaveTools::all(),
                PayrollTools::all(),
                ExperienceLetterTools::all(),
                array_slice($admin, 1),
            );
            self::$all = [];
            foreach ($list as $tool) {
                self::$all[$tool->name] = $tool;
            }
        }

        return self::$all;
    }

    /** What this deployment exposes right now (read-only mode hides everything that changes data). */
    public static function visible(bool $readOnly): array
    {
        return array_filter(self::all(), fn (Tool $t) => !$readOnly || $t->kind === Tool::READ);
    }
}
