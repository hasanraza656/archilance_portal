<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat video: screen recordings and video files get a duration, and can carry extra lower-quality copies
 * ("variants": 720p, 480p, 360p) so the player can switch down on a slow connection.
 *
 * Variants live in their OWN table, never as extra chat_attachments rows, so the Android app and the old
 * chat screens keep seeing exactly one attachment per video. Additive and guarded: safe to run twice.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('chat_attachment_variants')) {
            Schema::create('chat_attachment_variants', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('attachment_id')->index();
                $t->string('label', 12);                              // "720p"
                $t->string('file_path', 255);                         // where the copy lives on OneDrive
                $t->string('mime', 100)->nullable();
                $t->unsignedBigInteger('file_size')->nullable();
                $t->unsignedInteger('width')->nullable();
                $t->unsignedInteger('height')->nullable();
                $t->unsignedInteger('bitrate')->nullable();           // bits per second
                $t->timestamps();
                $t->unique(['attachment_id', 'label']);
            });
        }

        Schema::table('chat_attachments', function (Blueprint $t) {
            if (!Schema::hasColumn('chat_attachments', 'duration_ms')) {
                $t->unsignedInteger('duration_ms')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty: additive only.
    }
};
