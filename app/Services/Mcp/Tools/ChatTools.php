<?php

namespace App\Services\Mcp\Tools;

use App\Models\User;
use App\Services\Mcp\Present;
use App\Services\Mcp\S;
use App\Services\Mcp\Tool;
use App\Services\Mcp\ToolContext;
use App\Services\Mcp\ToolError;
use App\Support\ChatSchema;

/**
 * The admin's OWN chat inbox (direct messages and groups) - the same conversations they see in the portal's Chat page -
 * plus their notifications. Reading never marks anything as read; that is a separate, explicit tool.
 * Works with chat v2 when it is installed and falls back to the original chat otherwise.
 */
final class ChatTools
{
    /** @return Tool[] */
    public static function all(): array
    {
        return [self::inbox(), self::read(), self::thread(), self::send(), self::markRead(), self::search(), self::notifications()];
    }

    private static function v2(): bool
    {
        return ChatSchema::ready();
    }

    private static function msg(array $m, int $me): array
    {
        $files = [];
        foreach ((array) ($m['attachments'] ?? []) as $f) {
            $files[] = Present::clean(['name' => $f['file_name'] ?? null, 'type' => $f['kind'] ?? ($f['file_type'] ?? null), 'size_bytes' => $f['file_size'] ?? ($f['size'] ?? null)]);
        }
        $sender = $m['sender'] ?? null;
        $senderId = (int) ($m['sender_id'] ?? ($sender['id'] ?? 0));
        $reactions = [];
        foreach ((array) ($m['reactions'] ?? []) as $r) {
            $reactions[] = ($r['reaction'] ?? '?') . ' by ' . (($r['user_id'] ?? null) == $me ? 'me' : '#' . ($r['user_id'] ?? '?'));
        }

        return Present::clean([
            'id' => $m['id'] ?? null,
            'from' => $m['is_system'] ?? false ? 'system' : ($senderId === $me ? 'me' : Present::person($sender)),
            'text' => $m['message'] ?? null,
            'at' => $m['created_at'] ?? null,
            'edited' => !empty($m['edited_at']) ? true : null,
            'reply_to' => !empty($m['reply_to']) ? ['id' => $m['reply_to'], 'preview' => $m['parent']['text'] ?? ($m['parent']['message'] ?? null)] : null,
            'files' => $files,
            'reactions' => $reactions,
            'thread_replies' => $m['thread']['count'] ?? null,
            'seen_by_them' => isset($m['seen']) ? (($m['seen']['count'] ?? 0) > 0) : null,
        ]);
    }

    /** "user" / "group" + id, validated. */
    private static function target(array $a): array
    {
        $type = $a['type'] ?? 'user';
        if ($type === 'group' && !self::v2()) {
            throw new ToolError('Group chats need chat v2, which is not enabled on this server.');
        }

        return [$type, (int) $a['id']];
    }

    private static function inbox(): Tool
    {
        return new Tool(
            'chat_inbox',
            'Chat inbox / unread messages',
            'The signed-in admin\'s chat inbox: people and groups, most recent first, with unread counts and the last message. Use only_unread=true to see just what needs attention. Reading the inbox does not mark anything as read.',
            [
                'only_unread' => S::bool('Only conversations with unread messages (default false).'),
                'search' => S::str('Filter by person / group name (also finds people you have never chatted with).', 100),
                'limit' => S::limit(25, 100),
            ],
            [],
            Tool::READ,
            function (array $a, ToolContext $c) {
                $limit = $c->limit($a, 25, 100);
                $needle = mb_strtolower($a['search'] ?? '');
                $rows = [];
                $unreadTotal = 0;
                $unreadConvos = 0;

                $r = self::v2() ? $c->api->get('chat/v2/inbox') : ['ok' => false];
                if ($r['ok'] && !empty($r['data']['items'])) {
                    foreach ($r['data']['items'] as $i) {
                        $name = (string) ($i['name'] ?? '');
                        $unread = (int) ($i['unread_count'] ?? 0);
                        if ($needle !== '' && !str_contains(mb_strtolower($name), $needle)) {
                            continue;
                        }
                        if ($needle === '' && empty($i['has_messages'])) {
                            continue; // people nobody has talked to yet
                        }
                        if (!empty($a['only_unread']) && $unread < 1) {
                            continue;
                        }
                        $rows[] = Present::clean([
                            'type' => $i['type'],
                            'id' => $i['id'],
                            'name' => $name,
                            'role' => $i['type'] === 'user' ? PeopleTools::roleName($i['user_role'] ?? null) : null,
                            'members' => $i['members_count'] ?? null,
                            'unread' => $unread,
                            'last_message' => $i['last_message']['text'] ?? null,
                            'last_message_at' => $i['last_message']['created_at'] ?? ($i['last_activity_at'] ?? null),
                            'pinned' => !empty($i['pinned']) ? true : null,
                            'muted' => !empty($i['muted']) ? true : null,
                            'online' => !empty($i['online']) ? true : null,
                        ]);
                    }
                    $unreadTotal = (int) ($r['data']['unread_total'] ?? 0);
                    $unreadConvos = (int) ($r['data']['unread_conversations'] ?? 0);
                } else {
                    // The original chat (no groups, no mutes).
                    $leg = $c->api->must($c->api->get('chat/users-list'));
                    foreach ((array) ($leg['users'] ?? []) as $u) {
                        $name = (string) ($u['name'] ?? '');
                        $unread = (int) ($u['unread_count'] ?? 0);
                        if ($needle !== '' && !str_contains(mb_strtolower($name), $needle)) {
                            continue;
                        }
                        if ($needle === '' && empty($u['last_message'])) {
                            continue;
                        }
                        if (!empty($a['only_unread']) && $unread < 1) {
                            continue;
                        }
                        $unreadTotal += $unread;
                        $unreadConvos += $unread > 0 ? 1 : 0;
                        $rows[] = Present::clean([
                            'type' => 'user', 'id' => $u['id'], 'name' => $name, 'role' => PeopleTools::roleName($u['user_role'] ?? null),
                            'unread' => $unread, 'last_message' => $u['last_message'] ?? null, 'last_message_at' => $u['last_message_at'] ?? null,
                        ]);
                    }
                }

                return ['unread_messages_total' => $unreadTotal, 'conversations_with_unread' => $unreadConvos] + Present::page($rows, $limit);
            }
        );
    }

    private static function read(): Tool
    {
        return new Tool(
            'chat_read_conversation',
            'Read a conversation',
            'Read the messages of one conversation (a person or a group), oldest to newest within the page. Does NOT mark them read - call chat_mark_read for that. For older messages pass before_message_id (chat v2) or page (original chat).',
            [
                'type' => S::enum('user (default) or group.', ['user', 'group']),
                'id' => S::int('The person\'s user id, or the group id.', 1),
                'limit' => S::limit(20, 100),
                'before_message_id' => S::int('Chat v2: only messages older than this message id.'),
                'page' => S::int('Original chat only: page number (10 per page, 1 = newest).', 1),
            ],
            ['id'],
            Tool::READ,
            function (array $a, ToolContext $c) {
                [$type, $id] = self::target($a);
                $me = (int) $c->user->id;
                $who = $type === 'user' ? User::withTrashed()->find($id) : null;
                if ($type === 'user' && !$who) {
                    throw new ToolError('Person not found.');
                }

                if (self::v2()) {
                    $r = $c->api->must($c->api->get('chat/v2/conversation', array_filter([
                        'type' => $type, 'id' => $id, 'limit' => $c->limit($a, 20, 100), 'before_id' => $a['before_message_id'] ?? null,
                    ])));
                    $msgs = array_map(fn ($m) => self::msg($m, $me), (array) ($r['messages'] ?? []));

                    return Present::clean([
                        'conversation_with' => $who ? ['id' => (int) $who->id, 'name' => $who->name, 'role' => PeopleTools::roleName($who->user_role)] : ['group_id' => $id],
                        'has_older' => $r['has_more'] ?? null,
                        'oldest_message_id' => $r['oldest_id'] ?? null,
                        'messages' => $msgs,
                    ]);
                }

                $r = $c->api->must($c->api->get('chat/conversations/' . $id, ['page' => $a['page'] ?? 1]));

                return Present::clean([
                    'conversation_with' => ['id' => (int) $who->id, 'name' => $who->name, 'role' => PeopleTools::roleName($who->user_role)],
                    'page' => $r['current_page'] ?? 1,
                    'last_page' => $r['last_page'] ?? 1,
                    'messages' => array_map(fn ($m) => self::msg($m, $me), (array) ($r['data'] ?? [])),
                ]);
            }
        );
    }

    private static function thread(): Tool
    {
        return new Tool(
            'chat_read_thread',
            'Read a message thread',
            'Read one message and all replies in its thread (chat v2 threads).',
            ['message_id' => S::int('Id of the thread\'s top message (any message in the thread works).', 1)],
            ['message_id'],
            Tool::READ,
            function (array $a, ToolContext $c) {
                if (!self::v2()) {
                    throw new ToolError('Threads need chat v2, which is not enabled on this server.');
                }
                $me = (int) $c->user->id;
                $r = $c->api->must($c->api->get('chat/v2/messages/' . $a['message_id'] . '/thread'));

                return Present::clean(['message' => self::msg((array) ($r['root'] ?? []), $me), 'replies' => array_map(fn ($m) => self::msg($m, $me), (array) ($r['replies'] ?? []))]);
            }
        );
    }

    private static function send(): Tool
    {
        return new Tool(
            'chat_send_message',
            'Send a chat message',
            'Send a text message as the signed-in admin to a person or group (optionally as a reply to a message). The recipient gets the portal\'s normal notification. Text only - no attachments. In groups you can @-notify members with mention_user_ids.',
            [
                'type' => S::enum('user (default) or group.', ['user', 'group']),
                'id' => S::int('The person\'s user id, or the group id.', 1),
                'message' => S::str('The message.', 20000),
                'reply_to_message_id' => S::int('Reply to this message id.'),
                'mention_user_ids' => S::ids('Group chats: members to notify by mention.', 50),
            ],
            ['id', 'message'],
            Tool::WRITE,
            function (array $a, ToolContext $c) {
                [$type, $id] = self::target($a);
                if (trim($a['message']) === '') {
                    throw new ToolError('The message is empty.');
                }

                if (self::v2()) {
                    $r = $c->api->must($c->api->post('chat/v2/send', array_filter([
                        'type' => $type, 'id' => $id, 'message' => $a['message'],
                        'reply_to' => $a['reply_to_message_id'] ?? null,
                        'mentions' => $type === 'group' ? ($a['mention_user_ids'] ?? null) : null,
                        'client_id' => 'mcp-' . bin2hex(random_bytes(6)),
                    ], fn ($v) => $v !== null)));
                    $m = (array) ($r['chat'] ?? []);

                    return ['sent' => true, 'message_id' => $m['id'] ?? null, 'to' => $type . ':' . $id];
                }

                $r = $c->api->must($c->api->post('chat/send', array_filter([
                    'receiver_id' => $id, 'message' => $a['message'], 'reply_to' => $a['reply_to_message_id'] ?? null,
                ], fn ($v) => $v !== null)));

                return ['sent' => true, 'message_id' => $r['chat']['id'] ?? ($r['data']['id'] ?? ($r['id'] ?? null)), 'to' => 'user:' . $id];
            }
        );
    }

    private static function markRead(): Tool
    {
        return new Tool(
            'chat_mark_read',
            'Mark a conversation as read',
            'Mark everything in one conversation as read for the signed-in admin (the sender sees "seen"). Do this only after the user has actually read / dealt with the messages.',
            [
                'type' => S::enum('user (default) or group.', ['user', 'group']),
                'id' => S::int('The person\'s user id, or the group id.', 1),
            ],
            ['id'],
            Tool::WRITE,
            function (array $a, ToolContext $c) {
                [$type, $id] = self::target($a);
                if (self::v2()) {
                    $r = $c->api->must($c->api->post('chat/v2/read', ['type' => $type, 'id' => $id]));

                    return ['marked_read' => (int) ($r['marked'] ?? 0), 'conversation' => $type . ':' . $id];
                }
                $c->api->must($c->api->post('chat/bulk-read-status', ['user_id' => $id]));

                return ['marked_read' => 'all', 'conversation' => 'user:' . $id];
            }
        );
    }

    private static function search(): Tool
    {
        return new Tool(
            'chat_search',
            'Search inside a conversation',
            'Find messages (or file names) containing some text inside ONE conversation. Needs chat v2.',
            [
                'type' => S::enum('user (default) or group.', ['user', 'group']),
                'id' => S::int('The person\'s user id, or the group id.', 1),
                'query' => S::str('Text to find (2+ characters).', 100),
            ],
            ['id', 'query'],
            Tool::READ,
            function (array $a, ToolContext $c) {
                if (!self::v2()) {
                    throw new ToolError('Chat search needs chat v2, which is not enabled on this server.');
                }
                [$type, $id] = self::target($a);
                $r = $c->api->must($c->api->get('chat/v2/search', ['type' => $type, 'id' => $id, 'q' => $a['query']]));

                return Present::clean(['matches' => $r['results'] ?? []]);
            }
        );
    }

    private static function notifications(): Tool
    {
        return new Tool(
            'my_notifications',
            'My portal notifications',
            'The signed-in admin\'s portal notifications (assignments, comments, status changes, due dates...), newest first, with how many are unread. Does not mark them read.',
            ['page' => S::int('Page (10 per page).', 1)],
            [],
            Tool::READ,
            function (array $a, ToolContext $c) {
                $r = $c->api->must($c->api->get('my-notifications', ['page' => $a['page'] ?? 1]));
                $n = (array) ($r['notifications'] ?? []);

                return Present::clean([
                    'unread_total' => $r['total_unread'] ?? null,
                    'page' => $n['current_page'] ?? 1,
                    'last_page' => $n['last_page'] ?? 1,
                    'notifications' => array_map(fn ($x) => [
                        'id' => $x['id'] ?? null, 'type' => $x['notification_type'] ?? null, 'message' => $x['notification_message'] ?? null,
                        'project_or_task_id' => $x['project_id'] ?? null, 'read' => !empty($x['is_read']), 'at' => $x['created_at'] ?? null,
                    ], (array) ($n['data'] ?? [])),
                ]);
            }
        );
    }
}
