<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IT team feedback: one row per person. A row starts as "skipped" when they dismiss the
 * popup, and becomes "submitted" when they answer. Guarded with hasTable() so it is safe
 * to run after database/sql/2026_10_20_create_it_feedback.sql was applied by hand.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('it_feedback')) {
            return;
        }

        Schema::create('it_feedback', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('status', 20)->default('skipped'); // skipped | submitted
            $table->unsignedTinyInteger('overall')->nullable();
            $table->unsignedTinyInteger('satisfaction')->nullable();
            $table->unsignedTinyInteger('communication')->nullable();
            $table->unsignedTinyInteger('response')->nullable();
            $table->unsignedTinyInteger('resolution')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_feedback');
    }
};
