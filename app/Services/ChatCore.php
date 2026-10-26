<?php

namespace App\Services;

use App\Models\Chat;
use App\Models\ChatGroup;
use App\Models\ChatGroupMember;
use App\Models\ChatPeerSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Shared rules and shaping for chat v2 - who may talk to whom, who is in which group, and how a stored
 * message becomes the JSON the portal (and, later, the mobile app) reads.
 *
 * Nothing here touches the legacy ChatController or its endpoints.
 */
class ChatCore
{
    /** Number of member colours the front end defines (members are dealt colours in turn). */
    public const COLOR_COUNT = 12;

    /** Staff roles: admin, employee (managers/executives/interns), supervisor, executive. */
    public const STAFF_ROLES = [1, 2, 3, 6, 7];

    /* --------------------------------- people --------------------------------- */

    /** user_role ids the given user may start a 1:1 chat with (same rules the old chat list used). */
    public static function peerRoles(User $me): ?array
    {
        $role = (int) $me->user_role;
        if (in_array($role, [1, 2], true)) {
            return null; // everyone
        }
        if (in_array($role, [3, 6, 7], true)) {
            return self::STAFF_ROLES;
        }

        return [2]; // customers and their team members: admins only
    }

    public static function canChatWith(User $me, User $other): bool
    {
        if ($me->id === $other->id) {
            return false;
        }
        $roles = self::peerRoles($me);
        if ($roles !== null && !in_array((int) $other->user_role, $roles, true)) {
            return false;
        }
        // The other side must also be allowed to talk to me (a customer cannot be messaged by an employee).
        $theirs = self::peerRoles($other);

        return $theirs === null || in_array((int) $me->user_role, $theirs, true);
    }

    public static function isStaff(User $user): bool
    {
        return in_array((int) $user->user_role, self::STAFF_ROLES, true);
    }

    /* --------------------------------- groups --------------------------------- */

    public static function membership(int $groupId, int $userId): ?ChatGroupMember
    {
        return ChatGroupMember::where('group_id', $groupId)->where('user_id', $userId)->whereNull('left_at')->first();
    }

    /** The colour index a new member gets: the least used one in that group, so colours stay distinct. */
    public static function nextColor(int $groupId): int
    {
        $used = ChatGroupMember::where('group_id', $groupId)->pluck('color')->countBy()->all();
        $best = 0;
        $bestCount = PHP_INT_MAX;
        for ($i = 0; $i < self::COLOR_COUNT; $i++) {
            $n = $used[$i] ?? 0;
            if ($n < $bestCount) {
                $best = $i;
                $bestCount = $n;
            }
        }

        return $best;
    }

    /* ------------------------------ pin / mute ------------------------------- */

    /** @return Collection<string, ChatPeerSetting> keyed "user:12" / "group:3" */
    public static function settingsFor(int $userId): Collection
    {
        return ChatPeerSetting::where('user_id', $userId)->get()->keyBy(fn ($s) => $s->peer_type . ':' . $s->peer_id);
    }

    public static function isMutedFor(int $userId, string $peerType, int $peerId): bool
    {
        $s = ChatPeerSetting::where('user_id', $userId)->where('peer_type', $peerType)->where('peer_id', $peerId)->first();

        return $s ? $s->isMuted() : false;
    }

    /* ------------------------------- attachments ------------------------------ */

    public static function kindOf(?string $mime, ?string $name): string
    {
        $mime = strtolower((string) $mime);
        $name = strtolower((string) $name);

        if (str_starts_with($mime, 'image/') || preg_match('/\.(jpe?g|png|gif|webp|bmp|svg|heic)$/', $name)) {
            return 'image';
        }
        if ($mime === 'application/pdf' || str_ends_with($name, '.pdf')) {
            return 'pdf';
        }
        if (str_starts_with($mime, 'audio/') || preg_match('/\.(webm|m4a|mp3|ogg|wav|aac)$/', $name)) {
            return 'audio';
        }
        if (str_starts_with($mime, 'video/') || preg_match('/\.(mp4|mov|mkv|avi|webm)$/', $name)) {
            return 'video';
        }

        return 'file';
    }

    /** Relative (host-independent) signed link to a file; valid for 12 hours, regenerated on every fetch. */
    public static function fileUrl(int $attachmentId, string $variant = 'full', ?int $qualityId = null): string
    {
        $params = ['attachment' => $attachmentId, 'v' => $variant];
        if ($qualityId) {
            $params['var'] = $qualityId; // a lower-quality copy of a video
        }

        return URL::temporarySignedRoute('chat.v2.file', now()->addHours(12), $params, absolute: false);
    }

    /** @param  \Illuminate\Support\Collection|null  $variants  lower-quality copies of this video (rows of chat_attachment_variants) */
    public static function presentAttachment(object $a, $variants = null): array
    {
        $kind = self::kindOf($a->file_type ?? null, $a->file_name ?? null);

        return [
            'id' => (int) $a->id,
            'file_name' => $a->file_name,
            'file_type' => $a->file_type,
            'file_size' => $a->file_size !== null ? (int) $a->file_size : null,
            'kind' => $kind,
            'width' => $a->width ?? null,
            'height' => $a->height ?? null,
            'pages' => $a->pages ?? null,
            'url' => self::fileUrl((int) $a->id, 'full'),
            'thumb_url' => !empty($a->thumb_path) ? self::fileUrl((int) $a->id, 'thumb') : null,
            // PDFs are read in ranges by the viewer; images are fetched through the same proxy for annotation (the OneDrive link is cross-origin and would taint the canvas).
            'stream_url' => in_array($kind, ['pdf', 'image'], true) ? self::fileUrl((int) $a->id, 'stream') : null,
            'duration_ms' => isset($a->duration_ms) ? (int) $a->duration_ms : null,
            // Lower-quality copies of a video, highest first. The original is always there too (it is `url`).
            'variants' => $kind === 'video' && $variants
                ? $variants->sortByDesc('height')->map(fn ($v) => [
                    'id' => (int) $v->id,
                    'label' => $v->label,
                    'width' => $v->width !== null ? (int) $v->width : null,
                    'height' => $v->height !== null ? (int) $v->height : null,
                    'bitrate' => $v->bitrate !== null ? (int) $v->bitrate : null,
                    'file_size' => $v->file_size !== null ? (int) $v->file_size : null,
                    'url' => self::fileUrl((int) $a->id, 'full', (int) $v->id),
                ])->values()->all()
                : [],
            'created_at' => $a->created_at,
        ];
    }

    /** Replaces "#[Title](task:12)" / "#[Name](project:3)" tags with a readable "#Title". */
    public static function plainTags(string $message): string
    {
        return preg_replace('/#\[([^\]\n]{1,120})\]\((?:task|project):\d+\)/u', '#$1', $message) ?? $message;
    }

    /** Short text for the conversation list and notifications. */
    public static function previewText(?string $message, Collection $attachments): string
    {
        // "#[Title](task:12)" tags read as plain "#Title" in lists, notifications and emails.
        $text = trim(strip_tags(self::plainTags((string) $message)));
        if ($text !== '') {
            return mb_strlen($text) > 90 ? mb_substr($text, 0, 90) . '…' : $text;
        }
        $a = $attachments->first();
        if (!$a) {
            return '';
        }

        return match (self::kindOf($a->file_type ?? null, $a->file_name ?? null)) {
            'image' => '📷 Photo',
            'pdf' => '📄 ' . ($a->file_name ?: 'PDF'),
            'audio' => '🎤 Voice message',
            'video' => '🎬 Video',
            default => '📎 ' . ($a->file_name ?: 'File'),
        };
    }

    /* -------------------------------- messages -------------------------------- */

    /** UTC ISO-8601 ("...Z") for any Carbon / date string / null, whatever timezone the app stores in. */
    public static function iso($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($value)->toIso8601ZuluString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Turns stored chats into the JSON the clients read, in as few queries as possible.
     *
     * @param  Collection<int, Chat>  $chats
     * @param  ChatGroup|null  $group  set for a group conversation (drives the seen-by calculation)
     * @param  bool  $withThreads  attach reply counts to messages that started a thread
     */
    public static function presentMessages(Collection $chats, int $viewerId, ?ChatGroup $group = null, bool $withThreads = true): array
    {
        if ($chats->isEmpty()) {
            return [];
        }

        $ids = $chats->pluck('id')->all();

        $attachments = DB::table('chat_attachments')->whereIn('chat_id', $ids)->orderBy('id')->get()->groupBy('chat_id');
        $variantsBy = collect();
        if (\App\Support\ChatSchema::video() && $attachments->isNotEmpty()) {
            $attIds = $attachments->flatten(1)->pluck('id')->all();
            $variantsBy = DB::table('chat_attachment_variants')->whereIn('attachment_id', $attIds)->get()->groupBy('attachment_id');
        }
        $reactions = DB::table('chat_reactions')->whereIn('chat_id', $ids)->get(['chat_id', 'user_id', 'reaction'])->groupBy('chat_id');

        $senderIds = $chats->pluck('sender_id')->filter()->unique()->all();
        $parentIds = $chats->pluck('reply_to')->filter()->unique()->all();
        $parents = $parentIds ? Chat::withTrashed()->whereIn('id', $parentIds)->get(['id', 'sender_id', 'message'])->keyBy('id') : collect();
        $parentAtt = $parentIds ? DB::table('chat_attachments')->whereIn('chat_id', $parentIds)->get(['chat_id', 'file_type', 'file_name'])->groupBy('chat_id') : collect();
        $senderIds = array_values(array_unique(array_merge($senderIds, $parents->pluck('sender_id')->filter()->all())));
        $users = $senderIds ? User::withTrashed()->whereIn('id', $senderIds)->get(['id', 'name', 'profile_pic'])->keyBy('id') : collect();

        // 1:1 seen state = the receiver has a read row; group seen state = members whose last_read_id has passed it.
        $readBy = [];
        $members = collect();
        if ($group) {
            $members = ChatGroupMember::where('group_id', $group->id)->whereNull('left_at')->get(['user_id', 'last_read_id', 'color']);
        } else {
            $own = $chats->where('sender_id', $viewerId)->pluck('id')->all();
            if ($own) {
                $readBy = DB::table('chat_read_statuses')->whereIn('message_id', $own)->where('is_read', 1)->pluck('receiver_id', 'message_id')->all();
            }
        }

        $threads = [];
        if ($withThreads) {
            $threads = DB::table('chats')
                ->whereIn('thread_root_id', $ids)
                ->whereNull('deleted_at')
                ->select('thread_root_id', DB::raw('COUNT(*) as n'), DB::raw('MAX(created_at) as last_at'), DB::raw('MAX(id) as last_id'))
                ->groupBy('thread_root_id')
                ->get()
                ->keyBy('thread_root_id');
        }

        $userCard = fn (?int $id) => ($id && $users->has($id))
            ? ['id' => $users[$id]->id, 'name' => $users[$id]->name, 'profile_pic' => $users[$id]->profile_pic]
            : ['id' => $id, 'name' => 'Deleted user', 'profile_pic' => null];

        $out = [];
        foreach ($chats as $c) {
            $atts = collect($attachments->get($c->id, []));
            $parent = $c->reply_to ? $parents->get($c->reply_to) : null;

            $seen = null;
            if ($c->sender_id === $viewerId && !$c->is_system) {
                if ($group) {
                    $others = $members->where('user_id', '!=', $viewerId);
                    $seenIds = $others->filter(fn ($m) => (int) $m->last_read_id >= (int) $c->id)->pluck('user_id')->values()->all();
                    $seen = ['count' => count($seenIds), 'total' => $others->count(), 'user_ids' => $seenIds];
                } else {
                    $seen = ['count' => isset($readBy[$c->id]) ? 1 : 0, 'total' => 1, 'user_ids' => isset($readBy[$c->id]) ? [(int) $readBy[$c->id]] : []];
                }
            }

            $thread = null;
            if (isset($threads[$c->id])) {
                $t = $threads[$c->id];
                $thread = ['count' => (int) $t->n, 'last_at' => $t->last_at, 'last_id' => (int) $t->last_id];
            }

            $out[] = [
                'id' => (int) $c->id,
                'is_system' => (bool) $c->is_system,
                'sender_id' => $c->sender_id ? (int) $c->sender_id : null,
                'sender' => $userCard($c->sender_id ? (int) $c->sender_id : null),
                'receiver_id' => $c->receiver_id ? (int) $c->receiver_id : null,
                'group_id' => $c->group_id ? (int) $c->group_id : null,
                'message' => $c->message,
                'reply_to' => $c->reply_to ? (int) $c->reply_to : null,
                'parent' => $c->reply_to ? [
                    'id' => (int) $c->reply_to,
                    'sender' => $parent ? $userCard($parent->sender_id ? (int) $parent->sender_id : null) : null,
                    'text' => $parent ? self::previewText($parent->message, collect($parentAtt->get($parent->id, []))) : 'Original message deleted',
                    'deleted' => !$parent || $parent->deleted_at !== null,
                ] : null,
                'thread_root_id' => $c->thread_root_id ? (int) $c->thread_root_id : null,
                'thread' => $thread,
                'created_at' => self::iso($c->created_at),
                'edited_at' => self::iso($c->edited_at),
                'attachments' => $atts->map(fn ($a) => self::presentAttachment($a, $variantsBy->get($a->id)))->values()->all(),
                'reactions' => collect($reactions->get($c->id, []))->map(fn ($r) => ['user_id' => (int) $r->user_id, 'reaction' => $r->reaction])->values()->all(),
                'seen' => $seen,
            ];
        }

        return $out;
    }
}
