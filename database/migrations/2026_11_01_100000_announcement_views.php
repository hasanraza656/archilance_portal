<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per person per announcement popup they have seen, so a "what's new" notice shows once per person (on any
 * device) and never again after they close it.
 *
 * Additive and guarded: safe to run twice, touches no existing data.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('announcement_views')) {
            Schema::create('announcement_views', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->string('announcement_key', 80);
                $t->timestamp('seen_at')->nullable();
                $t->unique(['user_id', 'announcement_key'], 'announcement_views_unique');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: additive only.
    }
};
