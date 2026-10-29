<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual time can carry an attachment (proof of the work). The file itself lives in OneDrive like other attachments;
 * the session keeps its path, original name, type and size so it can be downloaded again from the work diary.
 *
 * Additive and guarded: safe to run twice, touches no existing data.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('work_sessions')) {
            return;
        }

        foreach ([
            'proof_path' => fn (Blueprint $t) => $t->string('proof_path', 255)->nullable(),
            'proof_name' => fn (Blueprint $t) => $t->string('proof_name', 190)->nullable(),
            'proof_type' => fn (Blueprint $t) => $t->string('proof_type', 120)->nullable(),
            'proof_size' => fn (Blueprint $t) => $t->unsignedInteger('proof_size')->nullable(),
        ] as $column => $add) {
            if (!Schema::hasColumn('work_sessions', $column)) {
                Schema::table('work_sessions', function (Blueprint $t) use ($add) {
                    $add($t);
                });
            }
        }
    }

    public function down(): void
    {
        // Intentionally empty: additive only.
    }
};
