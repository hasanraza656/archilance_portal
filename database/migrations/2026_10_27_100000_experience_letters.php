<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Experience letters: one row per generated certificate.
 *
 * The full certificate (values, wording, theme, list of counted projects) is kept as a JSON snapshot in `data`,
 * so a letter can be viewed and downloaded again exactly as it was issued, even if the employee's profile changes later.
 * Additive and guarded: safe to run twice, touches no existing table.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('experience_letters')) {
            Schema::create('experience_letters', function (Blueprint $t) {
                $t->id();
                $t->string('reference_no', 40)->unique();
                $t->unsignedBigInteger('employee_id')->index();
                $t->string('employee_name', 150);
                $t->string('designation', 150)->nullable();
                $t->date('start_date')->nullable();
                $t->date('end_date')->nullable();
                $t->date('issue_date');
                $t->unsignedInteger('projects_count')->default(0);
                $t->longText('data');                            // JSON snapshot: values, texts, theme, counted projects
                $t->string('source', 12)->default('portal');     // portal | mcp
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: additive only.
    }
};
