<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pending database changes, and a safe way to apply them from the admin UI.
 *
 * ── WHY THIS IS SAFE ───────────────────────────────────────────────────────
 *  - The list of updates is CODE (registry() below). The browser only ever says
 *    "apply what is pending"; it can never send SQL or pick a file.
 *  - Every update is ADDITIVE and idempotent: it creates a missing table or adds a
 *    missing column, guarded by hasTable()/hasColumn(). Nothing is dropped,
 *    renamed or rewritten, so no existing data can be lost by running one.
 *  - "Applied?" is decided by looking at the real schema (tables/columns exist),
 *    not by a bookkeeping flag — so a change that was already made by hand in
 *    phpMyAdmin is correctly shown as up to date, and running twice is a no-op.
 *  - Before touching anything it proves the database user may create/alter tables
 *    with a throw-away probe table, and it stops at the first failure so a later
 *    step never runs on top of a broken earlier one.
 *  - Runs are serialised with a lock, and every attempt is written to
 *    `system_db_updates` for the record (the log table is itself created on demand).
 *
 * To ship a new schema change: add a guarded migration file, then add one entry here.
 */
class DatabaseUpdates
{
    private const LOG_TABLE = 'system_db_updates';

    /**
     * Oldest first — the order matters when one update builds on another.
     *
     * @return array<int, array{key: string, title: string, description: string, files: string[], tables: string[], columns: array<string, string[]>}>
     */
    public static function registry(): array
    {
        return [
            [
                'key' => 'work_sessions_auto_closed_at',
                'title' => 'Work sessions: auto-close marker',
                'description' => 'Adds work_sessions.auto_closed_at so sessions closed automatically can be told apart.',
                'files' => ['2026_08_18_100000_add_auto_closed_at_to_work_sessions_table.php'],
                'tables' => [],
                'columns' => ['work_sessions' => ['auto_closed_at']],
            ],
            [
                'key' => 'users_gender',
                'title' => 'Users: gender',
                'description' => 'Adds users.gender (used for leave rules and the dashboard).',
                'files' => ['2026_08_20_100000_add_gender_to_users_table.php'],
                'tables' => [],
                'columns' => ['users' => ['gender']],
            ],
            [
                'key' => 'payroll_base',
                'title' => 'Payroll & salaries: core tables',
                'description' => 'Creates salary_profiles, salary_revisions, payroll_runs, payslips and payslip_items.',
                'files' => [
                    '2026_08_17_100000_create_salary_profiles_table.php',
                    '2026_08_17_100001_create_salary_revisions_table.php',
                    '2026_08_17_100002_create_payroll_runs_table.php',
                    '2026_08_17_100003_create_payslips_table.php',
                    '2026_08_17_100004_create_payslip_items_table.php',
                ],
                'tables' => ['salary_profiles', 'salary_revisions', 'payroll_runs', 'payslips', 'payslip_items'],
                'columns' => [],
            ],
            [
                'key' => 'employee_evaluations',
                'title' => 'Weekly evaluations',
                'description' => 'Creates the employee_evaluations table.',
                'files' => ['2026_10_08_100000_create_employee_evaluations_table.php'],
                'tables' => ['employee_evaluations'],
                'columns' => [],
            ],
            [
                'key' => 'task_assignees_is_manager',
                'title' => 'Project managers on tasks',
                'description' => 'Adds task_assignees.is_manager.',
                'files' => ['2026_10_09_110000_add_is_manager_to_task_assignees_table.php'],
                'tables' => [],
                'columns' => ['task_assignees' => ['is_manager']],
            ],
            [
                'key' => 'manager_evaluations',
                'title' => 'Bi-weekly manager ratings',
                'description' => 'Creates the manager_evaluations table.',
                'files' => ['2026_10_09_120000_create_manager_evaluations_table.php'],
                'tables' => ['manager_evaluations'],
                'columns' => [],
            ],
            [
                'key' => 'org_charts',
                'title' => 'Org chart: saved charts',
                'description' => 'Creates the org_charts table.',
                'files' => ['2026_10_12_100000_create_org_charts_table.php'],
                'tables' => ['org_charts'],
                'columns' => [],
            ],
            [
                'key' => 'payroll_advanced',
                'title' => 'Payroll: audit trail, recurring items, month closing',
                'description' => 'Adds the payroll audit log, standing monthly items, month-close and proration fields.',
                'files' => ['2026_10_17_100000_payroll_advanced.php'],
                'tables' => ['recurring_payroll_items', 'payroll_audit_logs'],
                'columns' => [
                    'payroll_runs' => ['paid_at', 'closed_at', 'closed_by', 'source'],
                    'payslips' => ['approved_by', 'approved_at', 'is_prorated', 'days_payable', 'days_in_month', 'status_reason'],
                    'payslip_items' => ['recurring_item_id'],
                ],
            ],
            [
                'key' => 'payroll_approvals',
                'title' => 'Payroll: approval requests',
                'description' => 'Creates the payroll_approval_requests table (executives ask an admin to approve a payroll).',
                'files' => ['2026_10_18_100000_create_payroll_approval_requests_table.php'],
                'tables' => ['payroll_approval_requests'],
                'columns' => [],
            ],
            [
                'key' => 'it_feedback',
                'title' => 'IT team feedback',
                'description' => 'Creates the it_feedback table (staff rate the IT team; only admins read the answers).',
                'files' => ['2026_10_20_100000_create_it_feedback_table.php'],
                'tables' => ['it_feedback'],
                'columns' => [],
            ],
            [
                'key' => 'chat_v2',
                'title' => 'Chat: groups, threads, pins, mutes, big files',
                'description' => 'Adds chat groups and members, pin/mute settings, presence, resumable upload sessions, and a few columns on chats / chat_attachments.',
                'files' => ['2026_10_24_100000_chat_v2.php'],
                'tables' => ['chat_groups', 'chat_group_members', 'chat_peer_settings', 'chat_presence', 'chat_uploads'],
                'columns' => [
                    'chats' => ['group_id', 'thread_root_id', 'is_system', 'edited_at'],
                    'chat_attachments' => ['thumb_path', 'width', 'height', 'pages'],
                ],
            ],
            [
                'key' => 'chat_video',
                'title' => 'Chat: screen recordings & video quality',
                'description' => 'Adds video duration and lower-quality copies (720p / 480p / 360p) so chat videos play smoothly on slow connections.',
                'files' => ['2026_10_25_100000_chat_video.php'],
                'tables' => ['chat_attachment_variants'],
                'columns' => [
                    'chat_attachments' => ['duration_ms'],
                ],
            ],
            [
                'key' => 'mcp_server',
                'title' => 'Claude connector (MCP) for admins',
                'description' => 'Adds the tables for the admin-only Claude connector: connected apps, sign-in codes, access tokens and an audit log of what Claude did.',
                'files' => ['2026_10_26_100000_mcp_server.php'],
                'tables' => ['mcp_clients', 'mcp_auth_codes', 'mcp_tokens', 'mcp_audit_logs'],
                'columns' => [],
            ],
            [
                'key' => 'users_hire_end_date',
                'title' => 'Users: hire end date',
                'description' => 'Adds users.hire_end_date (the last day / contract end of an employee).',
                'files' => ['2026_10_26_200000_add_hire_end_date_to_users_table.php'],
                'tables' => [],
                'columns' => ['users' => ['hire_end_date']],
            ],
            [
                'key' => 'experience_letters',
                'title' => 'Experience letters',
                'description' => 'Adds the table that stores generated experience letters (certificates) so they can be viewed and downloaded again.',
                'files' => ['2026_10_27_100000_experience_letters.php'],
                'tables' => ['experience_letters'],
                'columns' => [],
            ],
            [
                'key' => 'experience_letters_verify',
                'title' => 'Experience letters: QR verification',
                'description' => 'Adds the secret code behind the QR on each experience letter, so anyone can scan it and see the genuine letter on a public page.',
                'files' => ['2026_10_27_110000_experience_letters_verify_token.php'],
                'tables' => [],
                'columns' => ['experience_letters' => ['verify_token']],
            ],
            [
                'key' => 'payroll_providers_routes',
                'title' => 'Payroll: service providers and payout route',
                'description' => 'Adds the list of service providers that are paid through payroll without portal access, and a payout route (Payoneer or local) on salary profiles and payslips.',
                'files' => ['2026_10_28_100000_payroll_providers_and_routes.php'],
                'tables' => ['payroll_payees'],
                'columns' => ['salary_profiles' => ['payout_route'], 'payslips' => ['payout_route']],
            ],
            [
                'key' => 'offboarding',
                'title' => 'Offboarding',
                'description' => 'Adds the offboarding records (last working day, account removal date, reason, history) and a "why this amount" note on each payslip.',
                'files' => ['2026_10_29_100000_offboarding.php'],
                'tables' => ['offboardings', 'offboarding_logs'],
                'columns' => ['payslips' => ['pay_notes']],
            ],
            [
                'key' => 'offboarding_notice',
                'title' => 'Offboarding: notice date',
                'description' => 'Adds the day the notice was given, so the notice period (and leave taken during it) can be worked out.',
                'files' => ['2026_10_31_100000_offboarding_notice_date.php'],
                'tables' => [],
                'columns' => ['offboardings' => ['notice_date']],
            ],
            [
                'key' => 'announcements',
                'title' => 'Announcement popups',
                'description' => 'Adds the small table that remembers who has already seen a "what is new" popup, so it shows only once per person.',
                'files' => ['2026_11_01_100000_announcement_views.php'],
                'tables' => ['announcement_views'],
                'columns' => [],
            ],
            [
                'key' => 'work_session_proof',
                'title' => 'Work diary: manual time attachments',
                'description' => 'Adds the fields that remember an attachment uploaded with manual time, so it can be downloaded from the work diary.',
                'files' => ['2026_10_30_100000_work_session_proof.php'],
                'tables' => [],
                'columns' => ['work_sessions' => ['proof_path', 'proof_name', 'proof_type', 'proof_size']],
            ],
        ];
    }

    /** What is missing for one registry entry (empty array = fully applied). */
    private static function missing(array $step): array
    {
        $missing = [];

        foreach ($step['tables'] as $table) {
            if (!self::hasTable($table)) {
                $missing[] = "table {$table}";
            }
        }

        foreach ($step['columns'] as $table => $columns) {
            if (!self::hasTable($table)) {
                // The column's table is created by an earlier update; say so rather than failing.
                $missing[] = "table {$table} (needed first)";
                continue;
            }
            foreach ($columns as $column) {
                if (!self::hasColumn($table, $column)) {
                    $missing[] = "column {$table}.{$column}";
                }
            }
        }

        return $missing;
    }

    private static function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function hasColumn(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Current state of every update, plus the recent history. */
    public static function status(): array
    {
        $steps = [];
        $pending = 0;

        foreach (self::registry() as $step) {
            $missing = self::missing($step);
            $applied = empty($missing);
            if (!$applied) {
                $pending++;
            }

            $steps[] = [
                'key' => $step['key'],
                'title' => $step['title'],
                'description' => $step['description'],
                'applied' => $applied,
                'missing' => $missing,
            ];
        }

        return [
            'steps' => $steps,
            'pending_count' => $pending,
            'up_to_date' => $pending === 0,
            'history' => self::history(),
        ];
    }

    /** Last attempts, newest first. Empty when the log table does not exist yet. */
    private static function history(): array
    {
        if (!self::hasTable(self::LOG_TABLE)) {
            return [];
        }

        try {
            return DB::table(self::LOG_TABLE)
                ->orderByDesc('id')
                ->limit(25)
                ->get(['id', 'step_key', 'status', 'message', 'ran_by', 'ran_at'])
                ->map(fn ($r) => (array) $r)
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function ensureLogTable(): bool
    {
        if (self::hasTable(self::LOG_TABLE)) {
            return true;
        }

        try {
            Schema::create(self::LOG_TABLE, function ($t) {
                $t->id();
                $t->string('step_key', 80);
                $t->string('status', 16);
                $t->text('message')->nullable();
                $t->unsignedBigInteger('ran_by')->nullable();
                $t->timestamp('ran_at')->nullable();
            });

            return true;
        } catch (\Throwable $e) {
            return false; // logging is best-effort; never block the update itself
        }
    }

    private static function log(bool $enabled, string $key, string $status, ?string $message): void
    {
        if (!$enabled) {
            return;
        }

        try {
            DB::table(self::LOG_TABLE)->insert([
                'step_key' => $key,
                'status' => $status,
                'message' => $message !== null ? mb_substr($message, 0, 2000) : null,
                'ran_by' => Auth::id(),
                'ran_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /** Proves the DB user can create and drop a table before any real table is touched. */
    private static function preflight(): ?string
    {
        $probe = 'zz_dbu_probe_' . substr(md5((string) microtime(true)), 0, 6);

        try {
            Schema::create($probe, function ($t) {
                $t->id();
            });
            Schema::drop($probe);

            return null;
        } catch (\Throwable $e) {
            try {
                Schema::dropIfExists($probe);
            } catch (\Throwable $ignored) {
                // nothing more to do
            }

            return "The database account is not allowed to create/alter tables ({$e->getMessage()}). Nothing was changed.";
        }
    }

    /**
     * Applies every pending update, oldest first, stopping at the first failure.
     *
     * @return array{ok: bool, message: string, results: array<int, array<string, mixed>>}
     */
    public static function apply(): array
    {
        $pending = [];
        foreach (self::registry() as $step) {
            if (!empty(self::missing($step))) {
                $pending[] = $step;
            }
        }

        if (empty($pending)) {
            return ['ok' => true, 'message' => 'The database is already up to date. Nothing to change.', 'results' => []];
        }

        $lock = null;
        try {
            $lock = Cache::lock('system-db-update', 180);
            if (!$lock->get()) {
                return ['ok' => false, 'message' => 'Another update is already running. Try again in a minute.', 'results' => []];
            }
        } catch (\Throwable $e) {
            $lock = null; // cache unavailable: carry on, the steps are idempotent anyway
        }

        try {
            if ($problem = self::preflight()) {
                return ['ok' => false, 'message' => $problem, 'results' => []];
            }

            $logging = self::ensureLogTable();
            $results = [];
            $failed = false;

            foreach ($pending as $step) {
                if ($failed) {
                    $results[] = ['key' => $step['key'], 'title' => $step['title'], 'status' => 'skipped', 'message' => 'Not run because an earlier update failed.'];
                    continue;
                }

                try {
                    foreach ($step['files'] as $file) {
                        $path = database_path('migrations/' . $file);
                        if (!is_file($path)) {
                            throw new \RuntimeException("Update file {$file} is missing from the server. Upload it and try again.");
                        }

                        $migration = (function () use ($path) {
                            return require $path;
                        })();
                        $migration->up();
                    }

                    $stillMissing = self::missing($step);
                    if (!empty($stillMissing)) {
                        throw new \RuntimeException('Ran, but these are still missing: ' . implode(', ', $stillMissing));
                    }

                    $results[] = ['key' => $step['key'], 'title' => $step['title'], 'status' => 'applied', 'message' => 'Applied.'];
                    self::log($logging, $step['key'], 'applied', null);
                } catch (\Throwable $e) {
                    $failed = true;
                    $results[] = ['key' => $step['key'], 'title' => $step['title'], 'status' => 'failed', 'message' => $e->getMessage()];
                    self::log($logging, $step['key'], 'failed', $e->getMessage());
                }
            }

            $applied = count(array_filter($results, fn ($r) => $r['status'] === 'applied'));

            return [
                'ok' => !$failed,
                'message' => $failed
                    ? 'The update stopped at the first problem. Nothing already working was changed — see the details below.'
                    : ($applied . ' update' . ($applied === 1 ? '' : 's') . ' applied. The database is up to date.'),
                'results' => $results,
            ];
        } finally {
            try {
                $lock?->release();
            } catch (\Throwable $e) {
                // lock expires by itself
            }
        }
    }
}
