<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offboarding: one record per person leaving (last working day, the day the account is removed, why, who did it),
 * a small history of every change, and a plain-language "why this amount" list on each payslip.
 *
 * Additive and guarded: safe to run twice, touches no existing data.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('offboardings')) {
            Schema::create('offboardings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('status', 16)->default('Scheduled')->index(); // Scheduled | Completed | Cancelled | Reinstated
                $t->string('reason_type', 40)->default('Terminated');
                $t->text('reason')->nullable();
                $t->date('last_working_day');
                $t->date('removal_date')->index();
                $t->date('previous_hire_end_date')->nullable();
                $t->string('employee_name', 150)->nullable();
                $t->string('employee_email', 190)->nullable();
                $t->string('employee_type', 60)->nullable();
                $t->string('employee_team', 120)->nullable();
                $t->date('joining_date')->nullable();
                $t->unsignedBigInteger('initiated_by')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->timestamp('cancelled_at')->nullable();
                $t->unsignedBigInteger('cancelled_by')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('offboarding_logs')) {
            Schema::create('offboarding_logs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('offboarding_id')->index();
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->string('action', 40);
                $t->text('detail')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }

        if (Schema::hasTable('payslips') && !Schema::hasColumn('payslips', 'pay_notes')) {
            Schema::table('payslips', function (Blueprint $t) {
                $t->text('pay_notes')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: additive only.
    }
};
