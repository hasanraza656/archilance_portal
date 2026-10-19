<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval requests: an executive prepares a payroll and asks an admin to approve it.
 * One row per request; the PDF report that went out with the email is kept so the
 * approver can open exactly what was sent. Guarded with hasTable() so it is safe
 * after the SQL was run by hand.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('payroll_approval_requests')) {
            return;
        }

        Schema::create('payroll_approval_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('payroll_run_id')->index();
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->string('status', 24)->default('Pending')->index(); // Pending | Approved | Changes Requested | Rejected | Superseded
            $t->text('message')->nullable();
            $t->string('pdf_path', 255)->nullable();
            $t->decimal('total_net', 14, 2)->default(0);
            $t->unsignedInteger('employee_count')->default(0);
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->string('emailed_to', 500)->nullable();
            $t->boolean('email_ok')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_approval_requests');
    }
};
