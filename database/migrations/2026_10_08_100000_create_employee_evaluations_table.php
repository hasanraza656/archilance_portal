<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly evaluations: a reporting manager (users.manager_id) rates each of
 * their team members once per finished week — one overall evaluation per
 * person per week, not per project or task.
 *
 * Six criteria, each scored 1–10. Their weights (Quality 30%, Timely Delivery
 * 20%, Communication 15%, Understanding 15%, Ownership & Initiative 10%,
 * Productivity 10%) live in App\Models\EmployeeEvaluation::WEIGHTS and are only
 * applied when the overall percentage is computed, so a weight change never
 * needs a data migration.
 *
 * No foreign keys — integrity is enforced in application code, like the rest
 * of this schema. Guarded with hasTable() so it is safe to run via
 * `artisan migrate` AFTER the equivalent SQL
 * (database/sql/2026_10_08_create_employee_evaluations.sql) was applied by
 * hand on a host without shell access.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('employee_evaluations')) {
            return;
        }

        Schema::create('employee_evaluations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('manager_id');
            $table->unsignedBigInteger('employee_id');
            // Monday of the week being rated.
            $table->date('week_start');

            $table->unsignedTinyInteger('quality');
            $table->unsignedTinyInteger('timely_delivery');
            $table->unsignedTinyInteger('communication');
            $table->unsignedTinyInteger('understanding');
            $table->unsignedTinyInteger('ownership_initiative');
            $table->unsignedTinyInteger('productivity');
            $table->text('comments')->nullable();

            $table->timestamps();

            // One evaluation per person, per project manager, per week.
            $table->unique(['employee_id', 'manager_id', 'week_start'], 'employee_evaluations_unique_entry');
            $table->index(['manager_id', 'week_start'], 'employee_evaluations_manager_week');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_evaluations');
    }
};
