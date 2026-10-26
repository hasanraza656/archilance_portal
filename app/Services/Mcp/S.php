<?php

namespace App\Services\Mcp;

/** Tiny helpers that keep the tool definitions readable. */
final class S
{
    public static function int(string $desc, ?int $min = null, ?int $max = null): array
    {
        return array_filter(['type' => 'integer', 'description' => $desc, 'minimum' => $min, 'maximum' => $max], fn ($v) => $v !== null);
    }

    public static function str(string $desc, ?int $max = null): array
    {
        return array_filter(['type' => 'string', 'description' => $desc, 'maxLength' => $max], fn ($v) => $v !== null);
    }

    public static function date(string $desc): array
    {
        return ['type' => 'string', 'format' => 'date', 'description' => $desc . ' (YYYY-MM-DD)'];
    }

    public static function bool(string $desc): array
    {
        return ['type' => 'boolean', 'description' => $desc];
    }

    public static function enum(string $desc, array $values): array
    {
        return ['type' => 'string', 'description' => $desc, 'enum' => array_values($values)];
    }

    public static function ids(string $desc, int $max = 200): array
    {
        return ['type' => 'array', 'description' => $desc, 'items' => ['type' => 'integer'], 'maxItems' => $max];
    }

    public static function obj(string $desc): array
    {
        return ['type' => 'object', 'description' => $desc];
    }

    /** The common "how many rows" knob. */
    public static function limit(int $default = 25, int $max = 100): array
    {
        return ['type' => 'integer', 'description' => "How many rows to return (default {$default}, max {$max}).", 'minimum' => 1, 'maximum' => $max];
    }
}
