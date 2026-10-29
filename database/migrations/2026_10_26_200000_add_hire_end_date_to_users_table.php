<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * users.hire_end_date: an employee's last day / contract end. Optional and purely informational.
 * Additive and guarded: safe to run twice, touches no existing column or row.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'hire_end_date')) {
                $table->date('hire_end_date')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty: additive only.
    }
};
