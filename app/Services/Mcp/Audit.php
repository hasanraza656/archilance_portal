<?php

namespace App\Services\Mcp;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Append-only record of what Claude did. Failing to write it must never break the request. */
final class Audit
{
    private const SECRET_KEYS = ['password', 'secret', 'token', 'authorization', 'code', 'code_verifier'];

    public static function record(?int $userId, ?string $clientId, string $tool, string $status, ?string $summary, ?int $ms, ?string $ip): void
    {
        try {
            DB::table('mcp_audit_logs')->insert([
                'user_id' => $userId,
                'client_id' => $clientId,
                'tool' => mb_substr($tool, 0, 80),
                'status' => $status,
                'summary' => $summary !== null ? mb_substr($summary, 0, 1500) : null,
                'duration_ms' => $ms,
                'ip' => $ip ? mb_substr($ip, 0, 45) : null,
                'created_at' => Carbon::now(),
            ]);

            if (random_int(1, 100) === 1) {
                DB::table('mcp_audit_logs')->where('created_at', '<', Carbon::now()->subDays(180))->delete();
            }
        } catch (\Throwable $e) {
            // auditing is best-effort
        }
    }

    /** Arguments as a short string, with anything secret-looking hidden and long text cut. */
    public static function describeArgs(array $args): string
    {
        $mask = function ($v, $key = '') use (&$mask) {
            if (is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
                return '***';
            }
            if (is_array($v)) {
                $out = [];
                foreach ($v as $k => $x) {
                    $out[$k] = $mask($x, $k);
                }

                return $out;
            }
            if (is_string($v) && mb_strlen($v) > 160) {
                return mb_substr($v, 0, 160) . '…';
            }

            return $v;
        };

        return (string) json_encode($mask($args), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
