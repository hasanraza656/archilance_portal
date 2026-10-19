<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Answers "does this column / table exist yet?" for the payroll module, cached
 * per request.
 *
 * The advanced payroll features add columns and tables (see
 * database/sql/2026_10_17_payroll_advanced.sql). Laravel code may be uploaded
 * before that SQL is applied (System settings → Update database), so any code
 * that touches a new column goes through here: `only()` quietly drops fields the
 * database does not have yet instead of throwing, and `has()` lets a feature
 * switch itself off until its table exists.
 */
class PayrollSchema
{
    /** @var array<string, bool> */
    private static array $columns = [];

    /** @var array<string, bool> */
    private static array $tables = [];

    public static function table(string $table): bool
    {
        if (!array_key_exists($table, self::$tables)) {
            try {
                self::$tables[$table] = Schema::hasTable($table);
            } catch (\Throwable $e) {
                self::$tables[$table] = false;
            }
        }

        return self::$tables[$table];
    }

    public static function has(string $table, string $column): bool
    {
        $key = $table . '.' . $column;

        if (!array_key_exists($key, self::$columns)) {
            try {
                self::$columns[$key] = Schema::hasColumn($table, $column);
            } catch (\Throwable $e) {
                self::$columns[$key] = false;
            }
        }

        return self::$columns[$key];
    }

    /** The audit log + recurring items tables are present. */
    public static function advanced(): bool
    {
        return self::table('payroll_audit_logs') && self::table('recurring_payroll_items') && self::has('payslips', 'approved_by');
    }

    /** $data without any key whose column does not exist on $table yet. */
    public static function only(string $table, array $data): array
    {
        return array_filter($data, fn ($v, $k) => self::has($table, (string) $k), ARRAY_FILTER_USE_BOTH);
    }

    /** For tests: forget what was cached. */
    public static function flush(): void
    {
        self::$columns = [];
        self::$tables = [];
    }
}
