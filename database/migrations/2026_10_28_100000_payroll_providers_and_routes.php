<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll: service providers + payout route.
 *
 *  - payroll_payees: outside people / companies that are paid every month but do not use the portal (support and
 *    service providers). They are paid through the same salary profiles and payroll runs as employees.
 *  - salary_profiles.payout_route / payslips.payout_route: 'payoneer' (default) or 'local' (paid from a local
 *    wallet / bank, e.g. while the Payoneer account is not approved yet). The local ones are listed for the person
 *    who makes local payments.
 *
 * Additive and guarded: safe to run twice, touches no existing data.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('payroll_payees')) {
            Schema::create('payroll_payees', function (Blueprint $t) {
                $t->id();
                $t->string('name', 150);
                $t->string('company', 150)->nullable();
                $t->string('category', 80)->default('Service provider');
                $t->string('email', 190)->nullable();
                $t->string('phone', 60)->nullable();
                $t->string('country', 80)->nullable();
                $t->string('status', 16)->default('Active')->index();
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }

        if (Schema::hasTable('salary_profiles') && !Schema::hasColumn('salary_profiles', 'payout_route')) {
            Schema::table('salary_profiles', function (Blueprint $t) {
                $t->string('payout_route', 16)->default('payoneer');
            });
        }

        if (Schema::hasTable('payslips') && !Schema::hasColumn('payslips', 'payout_route')) {
            Schema::table('payslips', function (Blueprint $t) {
                $t->string('payout_route', 16)->default('payoneer');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: additive only.
    }
};
