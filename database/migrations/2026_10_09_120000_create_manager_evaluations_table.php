<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bi-weekly manager / executive ratings. One row = one person's rating of one
 * manager/executive for one 14-day period (`month_start` = the period's first day, a Monday). No foreign keys (integrity is enforced in
 * application code, like the rest of this schema). Guarded with hasTable() so it
 * is safe to run after database/sql/2026_10_09_create_manager_evaluations.sql
 * was applied by hand on a host without shell access.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('manager_evaluations')) {
            return;
        }

        Schema::create('manager_evaluations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rater_id');
            $table->unsignedBigInteger('ratee_id');
            $table->date('month_start');
            $table->string('kind', 32);
            $table->boolean('skipped')->default(false);
            $table->longText('scores')->nullable();
            $table->decimal('average', 4, 2)->nullable();
            $table->timestamps();

            $table->unique(['rater_id', 'ratee_id', 'month_start'], 'manager_evaluations_unique_entry');
            $table->index(['ratee_id', 'month_start'], 'manager_evaluations_ratee_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_evaluations');
    }
};
