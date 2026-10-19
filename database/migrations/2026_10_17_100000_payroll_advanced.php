<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll — advanced module: audit trail, recurring items, month closing,
 * proration. Mirrors database/sql/2026_10_17_payroll_advanced.sql and is guarded
 * column-by-column / table-by-table, so it is safe after the SQL was run by hand.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $t) {
            if (!Schema::hasColumn('payroll_runs', 'paid_at')) {
                $t->timestamp('paid_at')->nullable();
            }
            if (!Schema::hasColumn('payroll_runs', 'closed_at')) {
                $t->timestamp('closed_at')->nullable();
            }
            if (!Schema::hasColumn('payroll_runs', 'closed_by')) {
                $t->unsignedBigInteger('closed_by')->nullable();
            }
            if (!Schema::hasColumn('payroll_runs', 'source')) {
                $t->string('source', 16)->default('manual');
            }
        });

        Schema::table('payslips', function (Blueprint $t) {
            if (!Schema::hasColumn('payslips', 'approved_by')) {
                $t->unsignedBigInteger('approved_by')->nullable();
            }
            if (!Schema::hasColumn('payslips', 'approved_at')) {
                $t->timestamp('approved_at')->nullable();
            }
            if (!Schema::hasColumn('payslips', 'is_prorated')) {
                $t->boolean('is_prorated')->default(false);
            }
            if (!Schema::hasColumn('payslips', 'days_payable')) {
                $t->unsignedSmallInteger('days_payable')->nullable();
            }
            if (!Schema::hasColumn('payslips', 'days_in_month')) {
                $t->unsignedSmallInteger('days_in_month')->nullable();
            }
            if (!Schema::hasColumn('payslips', 'status_reason')) {
                $t->string('status_reason', 255)->nullable();
            }
        });

        Schema::table('payslip_items', function (Blueprint $t) {
            if (!Schema::hasColumn('payslip_items', 'recurring_item_id')) {
                $t->unsignedBigInteger('recurring_item_id')->nullable();
            }
        });

        if (!Schema::hasTable('recurring_payroll_items')) {
            Schema::create('recurring_payroll_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('kind', 16)->default('earning');
                $t->string('category', 80)->default('Allowance');
                $t->string('label', 190)->nullable();
                $t->decimal('amount', 12, 2)->default(0);
                $t->date('start_month');
                $t->date('end_month')->nullable();
                $t->boolean('is_active')->default(true)->index();
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('payroll_audit_logs')) {
            Schema::create('payroll_audit_logs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('payroll_run_id')->nullable()->index();
                $t->unsignedBigInteger('payslip_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->unsignedBigInteger('actor_id')->nullable();
                $t->string('action', 48);
                $t->string('from_value', 190)->nullable();
                $t->string('to_value', 190)->nullable();
                $t->decimal('amount', 12, 2)->nullable();
                $t->text('detail')->nullable();
                $t->timestamp('created_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_audit_logs');
        Schema::dropIfExists('recurring_payroll_items');
    }
};
