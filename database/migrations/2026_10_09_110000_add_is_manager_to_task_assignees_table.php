<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * is_manager — marks which assignees of a FIRST-LEVEL task (a "project" in the
 * UI; a "job" is a row of `projects`) act as that project's manager(s). A task
 * may have several. Drives who rates whom in the monthly manager evaluation: a
 * person rates the managers of the projects they worked on. Additive; defaults
 * to 0, so every existing assignee is unchanged.
 *
 * Guarded with hasColumn() so it is safe to run via `artisan migrate` after
 * the equivalent SQL (database/sql/2026_10_09_add_is_manager_to_task_assignees.sql)
 * was applied by hand on a host without shell access.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('task_assignees', 'is_manager')) {
            Schema::table('task_assignees', function (Blueprint $table) {
                $table->boolean('is_manager')->default(false)->after('task_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('task_assignees', 'is_manager')) {
            Schema::table('task_assignees', function (Blueprint $table) {
                $table->dropColumn('is_manager');
            });
        }
    }
};
