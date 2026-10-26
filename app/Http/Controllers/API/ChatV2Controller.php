<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\ChatAttachment;
use App\Models\ChatGroup;
use App\Models\ChatGroupMember;
use App\Models\ChatPeerSetting;
use App\Models\User;
use App\Services\ChatCore;
use App\Services\ChatInsights;
use App\Services\FcmService;
use App\Support\ChatSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Chat v2 for the web portal: a smart inbox (recent first, pins, mutes, groups), conversations with
 * seen-state, threads, and the contact insights panel.
 *
 * It lives next to - never inside - the old ChatController, and uses the same `chats` table, so every
 * message is visible to the Android app and the old screens exactly as before. Group messages and thread
 * replies are ordinary chat rows with a group_id / thread_root_id, which the old queries never match.
 */
class ChatV2Controller extends Controller
{
    private const PAGE = 40;

    private function unsupported()
    {
        return response()->json(['supported' => false, 'message' => 'Chat v2 needs a database update (System settings -> Update database).'], 503);
    }

    /** GET /api/chat/v2/status - lets the front end pick the new or the old chat screen. */
    public function status()
    {
        return response()->json(['supported' => ChatSchema::ready(), 'video' => ChatSchema::ready() && ChatSchema::video()]);
    }

    /* ----------------------------------------------------------------------------------------------
     * Inbox
     * -------------------------------------------------------------------------------------------- */

    /** GET /api/chat/v2/inbox - every person and group, most recent conversation first. */
    public function inbox()
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }

        $me = Auth::user();
        $this->touchPresence($me->id);

        $roles = ChatCore::peerRoles($me);
        $people = User::where('id', '!=', $me->id)
            ->when($roles !== null, fn ($q) => $q->whereIn('user_role', $roles))
            ->get(['id', 'name', 'profile_pic', 'user_role', 'employee_type', 'employee_team']);

        /* 1:1 - last message id and unread count per peer, in two queries instead of two per person. */
        $lastIds = collect(DB::select(
            'SELECT peer, MAX(id) AS last_id FROM (
                SELECT CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END AS peer, id
                FROM chats
                WHERE group_id IS NULL AND deleted_at IS NULL AND (sender_id = ? OR receiver_id = ?)
             ) t WHERE peer IS NOT NULL GROUP BY peer',
            [$me->id, $me->id, $me->id]
        ))->pluck('last_id', 'peer');

        $unread = DB::table('chats as c')
            ->where('c.receiver_id', $me->id)
            ->whereNull('c.group_id')
            ->whereNull('c.deleted_at')
            ->whereNotExists(function ($q) use ($me) {
                $q->select(DB::raw(1))->from('chat_read_statuses as r')
                    ->whereColumn('r.message_id', 'c.id')->where('r.receiver_id', $me->id)->where('r.is_read', 1);
            })
            ->select('c.sender_id', DB::raw('COUNT(*) as n'))->groupBy('c.sender_id')->pluck('n', 'sender_id');

        /* Groups I am in. */
        $memberships = ChatGroupMember::where('user_id', $me->id)->whereNull('left_at')->get()->keyBy('group_id');
        $groups = $memberships->isEmpty() ? collect() : ChatGroup::whereIn('id', $memberships->keys())->get()->keyBy('id');
        $groupLast = $groups->isEmpty() ? collect() : DB::table('chats')->whereIn('group_id', $groups->keys())->whereNull('deleted_at')
            ->select('group_id', DB::raw('MAX(id) as last_id'))->groupBy('group_id')->pluck('last_id', 'group_id');
        $groupCounts = $groups->isEmpty() ? collect() : DB::table('chat_group_members')->whereIn('group_id', $groups->keys())->whereNull('left_at')
            ->select('group_id', DB::raw('COUNT(*) as n'))->groupBy('group_id')->pluck('n', 'group_id');
        $groupUnread = [];
        foreach ($memberships as $gid => $m) {
            if (!$groups->has($gid)) {
                continue;
            }
            $groupUnread[$gid] = DB::table('chats')->where('group_id', $gid)->whereNull('deleted_at')->where('is_system', 0)
                ->where('id', '>', (int) $m->last_read_id)->where('sender_id', '!=', $me->id)->count();
        }

        /* Last messages (one query), their first attachment (one query), sender names (one query). */
        $messageIds = $lastIds->values()->merge($groupLast->values())->filter()->values();
        $lastMessages = $messageIds->isEmpty() ? collect() : Chat::whereIn('id', $messageIds)->get()->keyBy('id');
        $firstAtt = $messageIds->isEmpty() ? collect() : DB::table('chat_attachments')->whereIn('chat_id', $messageIds)->orderBy('id')->get(['chat_id', 'file_type', 'file_name'])->groupBy('chat_id');
        $senderNames = User::withTrashed()->whereIn('id', $lastMessages->pluck('sender_id')->filter()->unique())->pluck('name', 'id');

        $settings = ChatCore::settingsFor($me->id);
        $presence = DB::table('chat_presence')->pluck('last_seen_at', 'user_id');

        $shape = function (?Chat $m, bool $isGroup) use ($me, $firstAtt, $senderNames) {
            if (!$m) {
                return null;
            }
            $text = $m->is_system ? (string) $m->message : ChatCore::previewText($m->message, collect($firstAtt->get($m->id, [])));
            $mine = (int) $m->sender_id === (int) $me->id;
            if ($isGroup && !$m->is_system) {
                $text = ($mine ? 'You' : (explode(' ', (string) ($senderNames[$m->sender_id] ?? 'Someone'))[0])) . ': ' . $text;
            } elseif (!$isGroup && $mine) {
                $text = 'You: ' . $text;
            }

            return ['id' => (int) $m->id, 'text' => $text, 'sender_id' => $m->sender_id ? (int) $m->sender_id : null, 'mine' => $mine, 'created_at' => ChatCore::iso($m->created_at)];
        };

        $items = [];
        $now = now();

        foreach ($people as $p) {
            $m = $lastMessages->get($lastIds[$p->id] ?? 0);
            $s = $settings->get('user:' . $p->id);
            $seen = $presence[$p->id] ?? null;
            $items[] = [
                'key' => 'user:' . $p->id,
                'type' => 'user',
                'id' => (int) $p->id,
                'name' => $p->name,
                'profile_pic' => $p->profile_pic,
                'user_role' => (int) $p->user_role,
                'employee_type' => $p->employee_type,
                'employee_team' => $p->employee_team,
                'online' => $seen ? \Illuminate\Support\Carbon::parse($seen)->gt($now->copy()->subMinutes(2)) : false,
                'last_seen_at' => ChatCore::iso($seen),
                'last_message' => $shape($m, false),
                'last_activity_at' => $m ? ChatCore::iso($m->created_at) : null,
                'unread_count' => (int) ($unread[$p->id] ?? 0),
                'pinned' => (bool) ($s && $s->pinned_at),
                'pinned_at' => $s && $s->pinned_at ? ChatCore::iso($s->pinned_at) : null,
                'muted' => (bool) ($s && $s->isMuted()),
                'has_messages' => (bool) $m,
            ];
        }

        foreach ($groups as $g) {
            $m = $lastMessages->get($groupLast[$g->id] ?? 0);
            $s = $settings->get('group:' . $g->id);
            $items[] = [
                'key' => 'group:' . $g->id,
                'type' => 'group',
                'id' => (int) $g->id,
                'name' => $g->name,
                'avatar_url' => $g->avatar_path ? $this->groupAvatarUrl($g) : null,
                'members_count' => (int) ($groupCounts[$g->id] ?? 0),
                'my_role' => $memberships[$g->id]->role,
                'last_message' => $shape($m, true),
                'last_activity_at' => $m ? ChatCore::iso($m->created_at) : ChatCore::iso($g->created_at),
                'unread_count' => (int) ($groupUnread[$g->id] ?? 0),
                'pinned' => (bool) ($s && $s->pinned_at),
                'pinned_at' => $s && $s->pinned_at ? ChatCore::iso($s->pinned_at) : null,
                'muted' => (bool) ($s && $s->isMuted()),
                'has_messages' => true,
            ];
        }

        // Pinned first (most recently pinned on top), then by latest activity, then people nobody has talked to yet A-Z.
        usort($items, function ($a, $b) {
            if ($a['pinned'] !== $b['pinned']) {
                return $a['pinned'] ? -1 : 1;
            }
            if ($a['pinned'] && $b['pinned']) {
                return strcmp((string) $b['pinned_at'], (string) $a['pinned_at']);
            }
            if ($a['has_messages'] !== $b['has_messages']) {
                return $a['has_messages'] ? -1 : 1;
            }
            if ($a['has_messages']) {
                return strcmp((string) $b['last_activity_at'], (string) $a['last_activity_at']);
            }

            return strcasecmp($a['name'], $b['name']);
        });

        $unreadItems = array_filter($items, fn ($i) => $i['unread_count'] > 0 && !$i['muted']);

        return response()->json([
            'supported' => true,
            'items' => $items,
            'unread_total' => array_sum(array_column($unreadItems, 'unread_count')),
            'unread_conversations' => count($unreadItems),
        ]);
    }

    /* ----------------------------------------------------------------------------------------------
     * Conversation + threads
     * -------------------------------------------------------------------------------------------- */

    /** GET /api/chat/v2/conversation?type=user|group&id=..&before_id=..&limit=.. */
    public function conversation(Request $request)
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }

        $me = Auth::user();
        $data = $request->validate([
            'type' => 'required|in:user,group',
            'id' => 'required|integer',
            'before_id' => 'nullable|integer',
            'around_id' => 'nullable|integer',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);
        $limit = (int) ($data['limit'] ?? self::PAGE);

        $group = null;
        $query = Chat::query()->whereNull('thread_root_id');

        if ($data['type'] === 'group') {
            $group = ChatGroup::find($data['id']);
            if (!$group || !ChatCore::membership($group->id, $me->id)) {
                return response()->json(['message' => 'You are not in this group.'], 403);
            }
            $query->where('group_id', $group->id);
        } else {
            $other = User::find($data['id']);
            if (!$other) {
                return response()->json(['message' => 'Person not found.'], 404);
            }
            $query->whereNull('group_id')->where(function ($q) use ($me, $other) {
                $q->where(fn ($w) => $w->where('sender_id', $me->id)->where('receiver_id', $other->id))
                    ->orWhere(fn ($w) => $w->where('sender_id', $other->id)->where('receiver_id', $me->id));
            });
        }

        if (!empty($data['around_id'])) {
            // A window centred on one message (used when jumping to a search result).
            $around = (int) $data['around_id'];
            $older = (clone $query)->where('id', '<=', $around)->orderByDesc('id')->limit(21)->get();
            $newer = (clone $query)->where('id', '>', $around)->orderBy('id')->limit(20)->get();
            $hasMoreOlder = $older->count() > 20;
            $rows = $older->take(20)->reverse()->concat($newer)->values();

            return response()->json([
                'supported' => true,
                'messages' => ChatCore::presentMessages($rows, $me->id, $group),
                'has_more' => $hasMoreOlder,
                'oldest_id' => $rows->isNotEmpty() ? (int) $rows->first()->id : null,
                'around' => true,
            ]);
        }

        if (!empty($data['before_id'])) {
            $query->where('id', '<', (int) $data['before_id']);
        }

        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit)->reverse()->values();

        return response()->json([
            'supported' => true,
            'messages' => ChatCore::presentMessages($rows, $me->id, $group),
            'has_more' => $hasMore,
            'oldest_id' => $rows->isNotEmpty() ? (int) $rows->first()->id : null,
        ]);
    }

    /** GET /api/chat/v2/search?type=user|group&id=..&q=.. - messages (and file names) in one conversation, newest first. */
    public function search(Request $request)
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }

        $me = Auth::user();
        $data = $request->validate(['type' => 'required|in:user,group', 'id' => 'required|integer', 'q' => 'required|string|min:2|max:100']);

        $query = Chat::query()->where('is_system', 0);
        if ($data['type'] === 'group') {
            $group = ChatGroup::find($data['id']);
            if (!$group || !ChatCore::membership($group->id, $me->id)) {
                return response()->json(['message' => 'You are not in this group.'], 403);
            }
            $query->where('group_id', $group->id);
        } else {
            $other = User::find($data['id']);
            if (!$other) {
                return response()->json(['message' => 'Person not found.'], 404);
            }
            $query->whereNull('group_id')->where(function ($q) use ($me, $other) {
                $q->where(fn ($w) => $w->where('sender_id', $me->id)->where('receiver_id', $other->id))
                    ->orWhere(fn ($w) => $w->where('sender_id', $other->id)->where('receiver_id', $me->id));
            });
        }

        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $data['q']) . '%';
        $query->where(function ($q) use ($like) {
            $q->where('message', 'like', $like)
                ->orWhereExists(function ($e) use ($like) {
                    $e->select(DB::raw(1))->from('chat_attachments as a')->whereColumn('a.chat_id', 'chats.id')->where('a.file_name', 'like', $like);
                });
        });

        $rows = $query->orderByDesc('id')->limit(40)->get(['id', 'sender_id', 'message', 'thread_root_id', 'created_at']);
        $names = User::withTrashed()->whereIn('id', $rows->pluck('sender_id')->filter()->unique())->pluck('name', 'id');
        $files = DB::table('chat_attachments')->whereIn('chat_id', $rows->pluck('id'))->pluck('file_name', 'chat_id');

        return response()->json(['results' => $rows->map(fn ($m) => [
            'id' => (int) $m->id,
            'sender_id' => (int) $m->sender_id,
            'sender_name' => $names[$m->sender_id] ?? 'Someone',
            'text' => $m->message !== null && trim($m->message) !== '' ? $m->message : ($files[$m->id] ?? ''),
            'file_name' => $files[$m->id] ?? null,
            'thread_root_id' => $m->thread_root_id ? (int) $m->thread_root_id : null,
            'created_at' => ChatCore::iso($m->created_at),
        ])->values()]);
    }

    /** GET /api/chat/v2/messages/{id}/thread - the message and every reply in its thread. */
    public function thread($id)
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }

        $me = Auth::user();
        $root = Chat::find($id);
        if (!$root) {
            return response()->json(['message' => 'Message not found.'], 404);
        }
        if ($root->thread_root_id) {
            $root = Chat::find($root->thread_root_id) ?: $root; // always work from the top message
        }

        $group = null;
        if ($root->group_id) {
            $group = ChatGroup::find($root->group_id);
            if (!$group || !ChatCore::membership($group->id, $me->id)) {
                return response()->json(['message' => 'You are not in this group.'], 403);
            }
        } elseif ((int) $root->sender_id !== (int) $me->id && (int) $root->receiver_id !== (int) $me->id) {
            return response()->json(['message' => 'Not your conversation.'], 403);
        }

        $replies = Chat::where('thread_root_id', $root->id)->orderBy('id')->limit(500)->get();

        return response()->json([
            'root' => ChatCore::presentMessages(collect([$root]), $me->id, $group)[0],
            'replies' => ChatCore::presentMessages($replies, $me->id, $group, false),
        ]);
    }

    /* ----------------------------------------------------------------------------------------------
     * Sending, editing
     * -------------------------------------------------------------------------------------------- */

    /** POST /api/chat/v2/send */
    public function send(Request $request, FcmService $fcm)
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }

        $me = Auth::user();
        $data = $request->validate([
            'type' => 'required|in:user,group',
            'id' => 'required|integer',
            'message' => 'nullable|string|max:20000',
            'reply_to' => 'nullable|integer',
            'thread_root_id' => 'nullable|integer',
            'client_id' => 'nullable|string|max:64',
            'attachments' => 'nullable|array|max:20',
            'attachments.*.upload_id' => 'required|string',
            'attachments.*.thumb_path' => 'nullable|string|max:255',
            'attachments.*.width' => 'nullable|integer|min:1|max:20000',
            'attachments.*.height' => 'nullable|integer|min:1|max:20000',
            'attachments.*.pages' => 'nullable|integer|min:1|max:100000',
            'attachments.*.duration_ms' => 'nullable|integer|min:0|max:86400000',
            'mentions' => 'nullable|array|max:50',
            'mentions.*' => 'integer',
            'forward_attachment_ids' => 'nullable|array|max:20',
            'forward_attachment_ids.*' => 'integer',
        ]);

        $text = isset($data['message']) ? trim($data['message']) : '';
        $hasFiles = !empty($data['attachments']) || !empty($data['forward_attachment_ids']);
        if ($text === '' && !$hasFiles) {
            return response()->json(['message' => 'Write a message or attach a file.'], 422);
        }

        $group = null;
        $receiver = null;
        if ($data['type'] === 'group') {
            $group = ChatGroup::find($data['id']);
            if (!$group || !ChatCore::membership($group->id, $me->id)) {
                return response()->json(['message' => 'You are not in this group.'], 403);
            }
        } else {
            $receiver = User::find($data['id']);
            if (!$receiver || !ChatCore::canChatWith($me, $receiver)) {
                return response()->json(['message' => 'You cannot message this person.'], 403);
            }
        }

        // A reply inside a thread always hangs off the TOP message, wherever in the thread it was written.
        $threadRoot = null;
        if (!empty($data['thread_root_id'])) {
            $threadRoot = Chat::find($data['thread_root_id']);
            if ($threadRoot && $threadRoot->thread_root_id) {
                $threadRoot = Chat::find($threadRoot->thread_root_id);
            }
            if (!$threadRoot || !$this->sameConversation($threadRoot, $me->id, $group, $receiver)) {
                return response()->json(['message' => 'That thread is not in this conversation.'], 422);
            }
        }
        $replyTo = null;
        if (!empty($data['reply_to'])) {
            $replyTo = Chat::withTrashed()->find($data['reply_to']);
            if ($replyTo && !$this->sameConversation($replyTo->thread_root_id ? Chat::find($replyTo->thread_root_id) ?: $replyTo : $replyTo, $me->id, $group, $receiver)) {
                $replyTo = null;
            }
        }

        // Validate every referenced upload BEFORE writing anything.
        $uploads = [];
        foreach ($data['attachments'] ?? [] as $a) {
            $u = DB::table('chat_uploads')->where('id', $a['upload_id'])->where('user_id', $me->id)->first();
            if (!$u || $u->status !== 'done') {
                return response()->json(['message' => 'A file has not finished uploading.'], 422);
            }
            $thumb = $a['thumb_path'] ?? null;
            if ($thumb && !str_starts_with($thumb, 'chat_thumbs/' . $me->id . '/')) {
                $thumb = null;
            }
            $uploads[] = [$u, $thumb, $a];
        }
        $forwards = [];
        if (!empty($data['forward_attachment_ids'])) {
            foreach (DB::table('chat_attachments')->whereIn('id', $data['forward_attachment_ids'])->get() as $fa) {
                if ($this->canSeeAttachment($fa, $me->id)) {
                    $forwards[] = $fa;
                }
            }
        }

        $chat = DB::transaction(function () use ($me, $group, $receiver, $text, $threadRoot, $replyTo, $uploads, $forwards) {
            $chat = Chat::create([
                'sender_id' => $me->id,
                'receiver_id' => $receiver?->id,
                'group_id' => $group?->id,
                'message' => $text !== '' ? $text : null,
                'reply_to' => $replyTo?->id ?? ($threadRoot?->id),
                'thread_root_id' => $threadRoot?->id,
            ]);

            foreach ($uploads as [$u, $thumb, $a]) {
                ChatAttachment::create([
                    'chat_id' => $chat->id,
                    'user_id' => $me->id,
                    'file_path' => $u->remote_path,
                    'file_type' => $u->mime,
                    'file_size' => $u->file_size,
                    'file_name' => $u->file_name,
                    'thumb_path' => $thumb,
                    'width' => $a['width'] ?? null,
                    'height' => $a['height'] ?? null,
                    'pages' => $a['pages'] ?? null,
                ] + (ChatSchema::video() ? ['duration_ms' => $a['duration_ms'] ?? null] : []));
            }
            foreach ($forwards as $fa) {
                $copy = ChatAttachment::create([
                    'chat_id' => $chat->id,
                    'user_id' => $me->id,
                    'file_path' => $fa->file_path,
                    'file_type' => $fa->file_type,
                    'file_size' => $fa->file_size,
                    'file_name' => $fa->file_name,
                    'thumb_path' => $fa->thumb_path ?? null,
                    'width' => $fa->width ?? null,
                    'height' => $fa->height ?? null,
                    'pages' => $fa->pages ?? null,
                ] + (ChatSchema::video() ? ['duration_ms' => $fa->duration_ms ?? null] : []));
                // A forwarded video keeps its quality copies (the same files, referenced again).
                if (ChatSchema::video()) {
                    foreach (DB::table('chat_attachment_variants')->where('attachment_id', $fa->id)->get() as $v) {
                        DB::table('chat_attachment_variants')->insert([
                            'attachment_id' => $copy->id, 'label' => $v->label, 'file_path' => $v->file_path, 'mime' => $v->mime, 'file_size' => $v->file_size,
                            'width' => $v->width, 'height' => $v->height, 'bitrate' => $v->bitrate, 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            }

            return $chat;
        });

        // Sending counts as reading everything above it in a group.
        if ($group) {
            ChatGroupMember::where('group_id', $group->id)->where('user_id', $me->id)->update(['last_read_id' => $chat->id]);
        }

        $presented = ChatCore::presentMessages(collect([$chat->fresh()]), $me->id, $group)[0];
        $this->notify($fcm, $me, $chat, $presented, $group, $receiver, array_map('intval', $data['mentions'] ?? []));

        return response()->json(['message' => 'Sent.', 'chat' => $presented, 'client_id' => $data['client_id'] ?? null]);
    }

    /** POST /api/chat/v2/messages/{id}/edit */
    public function edit(Request $request, $id)
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }

        $chat = Chat::findOrFail($id);
        if ((int) $chat->sender_id !== (int) Auth::id() || $chat->is_system) {
            return response()->json(['message' => 'You can only edit your own messages.'], 403);
        }
        $data = $request->validate(['message' => 'required|string|max:20000']);
        $chat->update(['message' => trim($data['message']), 'edited_at' => now()]);

        $group = $chat->group_id ? ChatGroup::find($chat->group_id) : null;

        return response()->json(['chat' => ChatCore::presentMessages(collect([$chat->fresh()]), Auth::id(), $group)[0]]);
    }

    /* ----------------------------------------------------------------------------------------------
     * Read state, pins / mutes, presence
     * -------------------------------------------------------------------------------------------- */

    /** POST /api/chat/v2/read {type,id} - mark a conversation read up to now. */
    public function read(Request $request)
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }

        $me = Auth::user();
        $data = $request->validate(['type' => 'required|in:user,group', 'id' => 'required|integer']);
        $marked = 0;

        if ($data['type'] === 'group') {
            $max = (int) DB::table('chats')->where('group_id', $data['id'])->whereNull('deleted_at')->max('id');
            $marked = ChatGroupMember::where('group_id', $data['id'])->where('user_id', $me->id)->whereNull('left_at')
                ->where('last_read_id', '<', $max)->update(['last_read_id' => $max]);
        } else {
            // Same rows the old "bulk read" writes, so the Android app and this screen always agree.
            $ids = DB::table('chats as c')->where('c.sender_id', $data['id'])->where('c.receiver_id', $me->id)->whereNull('c.group_id')->whereNull('c.deleted_at')
                ->whereNotExists(function ($q) use ($me) {
                    $q->select(DB::raw(1))->from('chat_read_statuses as r')
                        ->whereColumn('r.message_id', 'c.id')->where('r.receiver_id', $me->id)->where('r.is_read', 1);
                })->pluck('c.id');

            foreach ($ids as $messageId) {
                $row = DB::table('chat_read_statuses')->where('message_id', $messageId)->where('receiver_id', $me->id)->first();
                if ($row) {
                    DB::table('chat_read_statuses')->where('id', $row->id)->update(['is_read' => 1, 'updated_at' => now()]);
                } else {
                    DB::table('chat_read_statuses')->insert(['message_id' => $messageId, 'receiver_id' => $me->id, 'is_read' => 1, 'created_at' => now(), 'updated_at' => now()]);
                }
                $marked++;
            }
        }

        return response()->json(['marked' => $marked]);
    }

    /** POST /api/chat/v2/settings {type,id,pinned?,muted?,mute_minutes?} */
    public function settings(Request $request)
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }

        $me = Auth::user();
        $data = $request->validate([
            'type' => 'required|in:user,group',
            'id' => 'required|integer',
            'pinned' => 'nullable|boolean',
            'muted' => 'nullable|boolean',
            'mute_minutes' => 'nullable|integer|min:1|max:525600',
        ]);

        $row = ChatPeerSetting::firstOrNew(['user_id' => $me->id, 'peer_type' => $data['type'], 'peer_id' => $data['id']]);

        if (array_key_exists('pinned', $data) && $data['pinned'] !== null) {
            if ($data['pinned']) {
                $pins = ChatPeerSetting::where('user_id', $me->id)->whereNotNull('pinned_at')->count();
                if (!$row->pinned_at && $pins >= 8) {
                    return response()->json(['message' => 'You can pin up to 8 chats. Unpin one first.'], 422);
                }
                $row->pinned_at = $row->pinned_at ?: now();
            } else {
                $row->pinned_at = null;
            }
        }
        if (array_key_exists('muted', $data) && $data['muted'] !== null) {
            $row->muted = (bool) $data['muted'];
            $row->muted_until = $data['muted'] && !empty($data['mute_minutes']) ? now()->addMinutes((int) $data['mute_minutes']) : null;
        }
        $row->save();

        return response()->json(['pinned' => (bool) $row->pinned_at, 'muted' => $row->isMuted(), 'muted_until' => ChatCore::iso($row->muted_until)]);
    }

    /** POST /api/chat/v2/ping - presence heartbeat ("online" / "last seen"). */
    public function ping()
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }
        $this->touchPresence(Auth::id());

        return response()->json(['ok' => true]);
    }

    /** GET /api/chat/v2/insights/{userId} - the contact panel: shared work, and (for leads) the person's workload. */
    public function insights($userId, ChatInsights $insights)
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }

        $me = Auth::user();
        $other = User::find($userId);
        if (!$other || !ChatCore::canChatWith($me, $other)) {
            return response()->json(['message' => 'Not available.'], 404);
        }

        return response()->json($insights->forPair($me, $other));
    }

    /* ----------------------------------------------------------------------------------------------
     * helpers
     * -------------------------------------------------------------------------------------------- */

    private function touchPresence(int $userId): void
    {
        // At most one write every 30 seconds per person, however often the client polls.
        if (!Cache::add('chat_presence_' . $userId, 1, 30)) {
            return;
        }
        $exists = DB::table('chat_presence')->where('user_id', $userId)->exists();
        if ($exists) {
            DB::table('chat_presence')->where('user_id', $userId)->update(['last_seen_at' => now()]);
        } else {
            DB::table('chat_presence')->insert(['user_id' => $userId, 'last_seen_at' => now()]);
        }
    }

    private function groupAvatarUrl(ChatGroup $g): string
    {
        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'chat.v2.groupavatar',
            now()->addHours(12),
            ['group' => $g->id, 'v' => optional($g->updated_at)->timestamp ?? 0],
            absolute: false
        );
    }

    private function sameConversation(Chat $root, int $meId, ?ChatGroup $group, ?User $receiver): bool
    {
        if ($group) {
            return (int) $root->group_id === (int) $group->id;
        }
        if ($root->group_id) {
            return false;
        }

        return ((int) $root->sender_id === $meId && (int) $root->receiver_id === (int) $receiver->id)
            || ((int) $root->sender_id === (int) $receiver->id && (int) $root->receiver_id === $meId);
    }

    /** May this person see an attachment (used for forwarding)? Only if they are part of the conversation it was sent in. */
    private function canSeeAttachment(object $att, int $meId): bool
    {
        $chat = Chat::withTrashed()->find($att->chat_id);
        if (!$chat) {
            return false;
        }
        if ($chat->group_id) {
            return (bool) ChatCore::membership((int) $chat->group_id, $meId);
        }

        return (int) $chat->sender_id === $meId || (int) $chat->receiver_id === $meId;
    }

    /**
     * Tells the people concerned: an in-app notification and a push, skipping anyone who muted this chat.
     * No email here - emails are decided later by the unread digest (see ChatDigest), so a busy chat never
     * floods an inbox.
     */
    private function notify(FcmService $fcm, User $me, Chat $chat, array $presented, ?ChatGroup $group, ?User $receiver, array $mentions = []): void
    {
        try {
            $preview = ChatCore::previewText($chat->message, collect($presented['attachments'])->map(fn ($a) => (object) $a));

            if ($receiver) {
                if (ChatCore::isMutedFor($receiver->id, 'user', $me->id)) {
                    return;
                }
                if (function_exists('chatMessageNotification')) {
                    chatMessageNotification($receiver, $me, $chat->message, false);
                }
                $fcm->send($receiver->fcm_token, $me->name, $preview !== '' ? $preview : 'New message', ['ref_id' => $me->id, 'chat_type' => 'user']);

                return;
            }

            $members = ChatGroupMember::where('group_id', $group->id)->whereNull('left_at')->where('user_id', '!=', $me->id)->pluck('user_id');
            $muted = ChatPeerSetting::whereIn('user_id', $members)->where('peer_type', 'group')->where('peer_id', $group->id)->get()->filter->isMuted()->pluck('user_id')->all();
            // Someone who was @mentioned is told even if they muted the group.
            $mentioned = $members->intersect($mentions);
            $targets = User::whereIn('id', $members->diff($muted)->merge($mentioned)->unique())->whereNotNull('fcm_token')->get(['id', 'fcm_token']);
            foreach ($targets as $t) {
                $first = explode(' ', $me->name)[0];
                $body = $mentioned->contains($t->id) ? $first . ' mentioned you: ' . ($preview !== '' ? $preview : '') : $first . ': ' . ($preview !== '' ? $preview : 'New message');
                $fcm->send($t->fcm_token, $group->name, $body, ['ref_id' => $group->id, 'chat_type' => 'group']);
            }
        } catch (\Throwable $e) {
            \Log::warning('Chat notify failed: ' . $e->getMessage());
        }
    }
}
