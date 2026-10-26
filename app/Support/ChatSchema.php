<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Whether the chat-v2 database update has been applied.
 *
 * The Laravel files can be uploaded before the SQL runs (System settings -> Update database), so every v2
 * chat endpoint asks here first and answers "not available yet" instead of throwing. The old chat
 * endpoints never call this and keep working exactly as before.
 */
class ChatSchema
{
    private static ?bool $ready = null;
    private static ?bool $video = null;

    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Schema::hasTable('chat_groups')
                    && Schema::hasTable('chat_group_members')
                    && Schema::hasTable('chat_peer_settings')
                    && Schema::hasTable('chat_presence')
                    && Schema::hasTable('chat_uploads')
                    && Schema::hasColumn('chats', 'group_id')
                    && Schema::hasColumn('chats', 'thread_root_id')
                    && Schema::hasColumn('chat_attachments', 'thumb_path');
            } catch (\Throwable $e) {
                self::$ready = false;
            }
        }

        return self::$ready;
    }

    /** Whether the chat-video update (video duration + quality copies) has been applied. Optional: videos work without it. */
    public static function video(): bool
    {
        if (self::$video === null) {
            try {
                self::$video = Schema::hasTable('chat_attachment_variants') && Schema::hasColumn('chat_attachments', 'duration_ms');
            } catch (\Throwable $e) {
                self::$video = false;
            }
        }

        return self::$video;
    }

    /** Tests (and the one-off "just applied" case) can ask again. */
    public static function flush(): void
    {
        self::$ready = null;
        self::$video = null;
    }
}
