<?php

namespace App\Services\Mcp;

/**
 * Turns the portal's (large, UI-shaped) JSON into something an AI can read cheaply:
 * no secrets, no empty fields, long lists and long text trimmed, and a hard size cap so a single answer can never
 * flood the conversation.
 */
class Present
{
    /** Never leave the server, whatever endpoint they come from. */
    private const NEVER = ['password', 'remember_token', 'fcm_token', 'api_token', 'access_token', 'refresh_token', 'secret', 'two_factor_secret', 'pivot', 'updated_at', 'email_verified_at'];

    /** Noise for an AI reader (the portal's UI needs them, a conversation does not). */
    private const NOISE = ['profile_pic', 'ip_address', 'last_message_email_sent_at', 'email_notification_preferences', 'file_path', 'thumb_path', 'screenshot_file', 'emp_screenshot_file'];

    public const MAX_CHARS = 60000;

    /** Recursively removes secrets / noise / empty values and trims long text. */
    public static function clean(mixed $value, int $maxText = 3000, int $depth = 0): mixed
    {
        if ($depth > 12) {
            return null;
        }
        if (is_object($value)) {
            $value = method_exists($value, 'toArray') ? $value->toArray() : (array) $value;
        }
        if (is_string($value)) {
            return mb_strlen($value) > $maxText ? mb_substr($value, 0, $maxText) . '…[truncated]' : $value;
        }
        if (!is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $k => $v) {
            if (!$isList && in_array((string) $k, self::NEVER, true)) {
                continue;
            }
            if (!$isList && in_array((string) $k, self::NOISE, true)) {
                continue;
            }
            $v = self::clean($v, $maxText, $depth + 1);
            if (!$isList && ($v === null || $v === '' || $v === [])) {
                continue;
            }
            $isList ? $out[] = $v : $out[$k] = $v;
        }

        return $out;
    }

    /** Smallest "who" reference: id + name. */
    public static function person(mixed $u): ?array
    {
        if (is_array($u)) {
            $id = $u['id'] ?? null;
            $name = $u['name'] ?? null;
        } elseif (is_object($u)) {
            $id = $u->id ?? null;
            $name = $u->name ?? null;
        } else {
            return null;
        }

        return $id === null && $name === null ? null : array_filter(['id' => $id, 'name' => $name], fn ($x) => $x !== null);
    }

    /** JSON text for the AI, capped. Lists are shortened step by step until it fits. */
    public static function json(mixed $data, int $max = self::MAX_CHARS): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
        $text = (string) json_encode($data, $flags);
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        foreach ([50, 25, 12, 6, 3] as $cap) {
            $shrunk = self::capLists($data, $cap);
            $wrapped = ['_note' => "Result was too large, so long lists were cut to {$cap} items. Ask for a narrower filter / date range / page to see the rest.", 'data' => $shrunk];
            $text = (string) json_encode($wrapped, $flags);
            if (mb_strlen($text) <= $max) {
                return $text;
            }
        }

        return mb_substr($text, 0, $max) . '…[cut: result too large - use filters]';
    }

    private static function capLists(mixed $v, int $cap): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        if (array_is_list($v)) {
            $total = count($v);
            $v = array_slice($v, 0, $cap);
            $v = array_map(fn ($x) => self::capLists($x, $cap), $v);
            if ($total > $cap) {
                $v[] = "… +" . ($total - $cap) . ' more';
            }

            return $v;
        }

        return array_map(fn ($x) => self::capLists($x, $cap), $v);
    }

    /** "2h 05m" from seconds. */
    public static function hm(int|float|null $seconds): string
    {
        $s = (int) max(0, round((float) $seconds));

        return sprintf('%dh %02dm', intdiv($s, 3600), intdiv($s % 3600, 60));
    }

    /** Next to every "*_seconds" number, adds a readable "*_hm" ("3h 05m") so the AI never has to divide. */
    public static function durations(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        $out = [];
        foreach ($v as $k => $x) {
            $out[$k] = self::durations($x);
            if (is_string($k) && (is_int($x) || is_float($x)) && str_contains($k, 'seconds')) {
                $out[str_replace('seconds', 'hm', $k)] = self::hm($x);
            }
        }

        return $out;
    }

    /** First n items of a list plus an honest count. */
    public static function page(array $items, int $limit, ?int $total = null): array
    {
        $total ??= count($items);

        return ['total' => $total, 'shown' => min($limit, count($items)), 'items' => array_slice($items, 0, $limit)];
    }
}
