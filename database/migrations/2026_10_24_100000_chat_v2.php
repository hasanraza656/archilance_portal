<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat v2: groups, threads, pins / mutes, presence, big-file uploads and image/PDF thumbnails.
 *
 * Everything is ADDITIVE and guarded (hasTable / hasColumn), so it is safe to run twice and safe after
 * database/sql/2026_10_24_chat_v2.sql was run by hand. Existing chat rows and the Android app's
 * endpoints are untouched: a group message is simply a `chats` row with a group_id (and no receiver),
 * and a thread reply is a `chats` row with a thread_root_id.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('chat_groups')) {
            Schema::create('chat_groups', function (Blueprint $t) {
                $t->id();
                $t->string('name', 120);
                $t->text('description')->nullable();
                $t->string('avatar_path', 255)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }

        if (!Schema::hasTable('chat_group_members')) {
            Schema::create('chat_group_members', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('group_id')->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('role', 10)->default('member');          // admin | member
                $t->unsignedTinyInteger('color')->default(0);        // index into the front-end palette
                $t->unsignedBigInteger('added_by')->nullable();
                $t->unsignedBigInteger('last_read_id')->default(0);  // highest chats.id this member has seen
                $t->timestamp('joined_at')->nullable();
                $t->timestamp('left_at')->nullable();                // set when removed / left; history is kept
                $t->timestamps();
                $t->unique(['group_id', 'user_id']);
            });
        }

        if (!Schema::hasTable('chat_peer_settings')) {
            Schema::create('chat_peer_settings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('peer_type', 8);                          // user | group
                $t->unsignedBigInteger('peer_id');
                $t->timestamp('pinned_at')->nullable();
                $t->boolean('muted')->default(false);
                $t->timestamp('muted_until')->nullable();            // null + muted = until unmuted
                $t->timestamps();
                $t->unique(['user_id', 'peer_type', 'peer_id']);
            });
        }

        if (!Schema::hasTable('chat_presence')) {
            Schema::create('chat_presence', function (Blueprint $t) {
                $t->unsignedBigInteger('user_id')->primary();
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamp('last_digest_at')->nullable();         // last "unread messages" email
            });
        }

        if (!Schema::hasTable('chat_uploads')) {
            Schema::create('chat_uploads', function (Blueprint $t) {
                $t->string('id', 40)->primary();                     // random token handed to the browser
                $t->unsignedBigInteger('user_id')->index();
                $t->string('file_name', 255);
                $t->string('mime', 120)->nullable();
                $t->unsignedBigInteger('file_size')->default(0);
                $t->string('remote_path', 255);                      // where it lives on OneDrive
                $t->text('upload_url')->nullable();                  // Graph upload-session URL
                $t->unsignedBigInteger('bytes_received')->default(0);
                $t->string('status', 12)->default('open');           // open | done | failed
                $t->timestamps();
            });
        }

        Schema::table('chats', function (Blueprint $t) {
            if (!Schema::hasColumn('chats', 'group_id')) {
                $t->unsignedBigInteger('group_id')->nullable()->index();
            }
            if (!Schema::hasColumn('chats', 'thread_root_id')) {
                $t->unsignedBigInteger('thread_root_id')->nullable()->index();
            }
            if (!Schema::hasColumn('chats', 'is_system')) {
                $t->boolean('is_system')->default(false);
            }
            if (!Schema::hasColumn('chats', 'edited_at')) {
                $t->timestamp('edited_at')->nullable();
            }
        });

        Schema::table('chat_attachments', function (Blueprint $t) {
            if (!Schema::hasColumn('chat_attachments', 'thumb_path')) {
                $t->string('thumb_path', 255)->nullable();
            }
            if (!Schema::hasColumn('chat_attachments', 'width')) {
                $t->unsignedInteger('width')->nullable();
            }
            if (!Schema::hasColumn('chat_attachments', 'height')) {
                $t->unsignedInteger('height')->nullable();
            }
            if (!Schema::hasColumn('chat_attachments', 'pages')) {
                $t->unsignedInteger('pages')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty: this feature only adds structure, and dropping columns that live
        // data may by then depend on is never done automatically.
    }
};
