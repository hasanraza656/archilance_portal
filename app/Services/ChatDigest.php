<?php

namespace App\Services;

use App\Models\ChatPeerSetting;
use App\Models\User;
use App\Support\ChatSchema;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY place chat emails come from - one calm "you have unread messages" digest, never one email per message.
 *
 * Rules (all of them must hold before anyone is emailed):
 *   - the message has stayed UNREAD for at least 15 minutes (people who answer quickly never get an email);
 *   - the person has not been active in the portal in the last 10 minutes;
 *   - the conversation is not muted;
 *   - it is between 08:00 and 21:00 in the app timezone (night messages wait for the morning);
 *   - at most one digest per person every 2 hours, and it only lists what arrived since the last one;
 *   - messages older than 3 days are never chased.
 * Customers and their team members are skipped by sendNotificationEmail() itself, as before.
 * Run every few minutes from the scheduler (routes/console.php).
 */
class ChatDigest
{
    public const UNREAD_FOR_MINUTES = 15;
    public const ACTIVE_WITHIN_MINUTES = 10;
    public const MIN_GAP_HOURS = 2;
    public const MAX_AGE_DAYS = 3;
    public const MAX_SOURCES = 5;

    /** @return int number of digests sent */
    public function run(?Carbon $now = null): int
    {
        if (!ChatSchema::ready()) {
            return 0;
        }

        $now = $now ?: now();
        $hour = (int) $now->copy()->setTimezone(config('app.timezone'))->format('G');
        if ($hour < 8 || $hour >= 21) {
            return 0;
        }

        $ripe = $now->copy()->subMinutes(self::UNREAD_FOR_MINUTES);
        $stale = $now->copy()->subDays(self::MAX_AGE_DAYS);

        /** @var array<int, array<string, array{name:string,msgs:array}>> $perUser */
        $perUser = [];

        // 1:1 - unread messages addressed to someone.
        $direct = DB::table('chats as c')
            ->whereNotNull('c.receiver_id')->whereNull('c.group_id')->whereNull('c.deleted_at')
            ->where('c.created_at', '<=', $ripe)->where('c.created_at', '>=', $stale)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('chat_read_statuses as r')
                    ->whereColumn('r.message_id', 'c.id')->whereColumn('r.receiver_id', 'c.receiver_id')->where('r.is_read', 1);
            })
            ->orderBy('c.id')->get(['c.id', 'c.sender_id', 'c.receiver_id', 'c.message', 'c.created_at']);

        $senderNames = User::withTrashed()->whereIn('id', $direct->pluck('sender_id')->unique())->pluck('name', 'id');
        $directIds = $direct->pluck('id')->all();
        $att = $directIds ? DB::table('chat_attachments')->whereIn('chat_id', $directIds)->orderBy('id')->get(['chat_id', 'file_type', 'file_name'])->groupBy('chat_id') : collect();

        foreach ($direct as $m) {
            $key = 'user:' . $m->sender_id;
            $slot = &$perUser[$m->receiver_id][$key];
            $slot ??= ['name' => $senderNames[$m->sender_id] ?? 'Someone', 'msgs' => []];
            $slot['msgs'][] = ['id' => (int) $m->id, 'at' => $m->created_at, 'text' => ChatCore::previewText($m->message, collect($att->get($m->id, [])))];
            unset($slot);
        }

        // Groups - everything after each member's last-read mark.
        $members = DB::table('chat_group_members as gm')->join('chat_groups as g', 'g.id', '=', 'gm.group_id')
            ->whereNull('gm.left_at')->whereNull('g.deleted_at')->get(['gm.group_id', 'gm.user_id', 'gm.last_read_id', 'g.name']);
        $byGroup = $members->groupBy('group_id');
        foreach ($byGroup as $groupId => $rows) {
            $minRead = (int) $rows->min('last_read_id');
            $msgs = DB::table('chats')->where('group_id', $groupId)->whereNull('deleted_at')->where('is_system', 0)
                ->where('id', '>', $minRead)->where('created_at', '<=', $ripe)->where('created_at', '>=', $stale)
                ->orderBy('id')->get(['id', 'sender_id', 'message', 'created_at']);
            if ($msgs->isEmpty()) {
                continue;
            }
            $gAtt = DB::table('chat_attachments')->whereIn('chat_id', $msgs->pluck('id'))->orderBy('id')->get(['chat_id', 'file_type', 'file_name'])->groupBy('chat_id');
            $names = User::withTrashed()->whereIn('id', $msgs->pluck('sender_id')->unique())->pluck('name', 'id');

            foreach ($rows as $mem) {
                foreach ($msgs as $m) {
                    if ((int) $m->id <= (int) $mem->last_read_id || (int) $m->sender_id === (int) $mem->user_id) {
                        continue;
                    }
                    $key = 'group:' . $groupId;
                    $slot = &$perUser[$mem->user_id][$key];
                    $slot ??= ['name' => $mem->name, 'msgs' => []];
                    $slot['msgs'][] = [
                        'id' => (int) $m->id, 'at' => $m->created_at,
                        'text' => explode(' ', (string) ($names[$m->sender_id] ?? 'Someone'))[0] . ': ' . ChatCore::previewText($m->message, collect($gAtt->get($m->id, []))),
                    ];
                    unset($slot);
                }
            }
        }

        if (!$perUser) {
            return 0;
        }

        $users = User::whereIn('id', array_keys($perUser))->get()->keyBy('id');
        $presence = DB::table('chat_presence')->whereIn('user_id', array_keys($perUser))->get()->keyBy('user_id');
        $sent = 0;

        foreach ($perUser as $userId => $sources) {
            $user = $users->get($userId);
            if (!$user || empty($user->email)) {
                continue;
            }

            $p = $presence->get($userId);
            if ($p && $p->last_seen_at && Carbon::parse($p->last_seen_at)->gt($now->copy()->subMinutes(self::ACTIVE_WITHIN_MINUTES))) {
                continue; // in the portal right now - they will see it
            }
            $lastDigest = $p && $p->last_digest_at ? Carbon::parse($p->last_digest_at) : null;
            if ($lastDigest && $lastDigest->gt($now->copy()->subHours(self::MIN_GAP_HOURS))) {
                continue;
            }

            // Drop muted conversations, and anything already covered by the previous digest.
            $muted = ChatPeerSetting::where('user_id', $userId)->get()->filter->isMuted()->map(fn ($s) => $s->peer_type . ':' . $s->peer_id)->all();
            $items = [];
            foreach ($sources as $key => $s) {
                if (in_array($key, $muted, true)) {
                    continue;
                }
                // Only what arrived since the previous digest.
                $fresh = $lastDigest ? array_values(array_filter($s['msgs'], fn ($m) => Carbon::parse($m['at'])->gt($lastDigest))) : $s['msgs'];
                if (!$fresh) {
                    continue;
                }
                $last = end($fresh);
                $items[] = ['name' => $s['name'], 'count' => count($fresh), 'last' => $last['text'], 'last_id' => $last['id']];
            }
            if (!$items) {
                continue;
            }

            usort($items, fn ($a, $b) => $b['last_id'] <=> $a['last_id']);
            $total = array_sum(array_column($items, 'count'));
            $shown = array_slice($items, 0, self::MAX_SOURCES);
            $more = count($items) - count($shown);

            $details = array_map(fn ($s) => [
                'label' => $s['name'],
                'value' => $s['count'] . ($s['count'] === 1 ? ' new message' : ' new messages') . ' - "' . mb_substr($s['last'], 0, 80) . '"',
            ], $shown);
            if ($more > 0) {
                $details[] = ['label' => '+ ' . $more . ' more', 'value' => 'in other conversations'];
            }

            $who = count($items) === 1 ? $items[0]['name'] : count($items) . ' conversations';
            sendNotificationEmail($user, 'You have ' . $total . ' unread ' . ($total === 1 ? 'message' : 'messages') . ' on Archilance', [
                'emoji' => '💬',
                'heading' => $total . ' unread ' . ($total === 1 ? 'message' : 'messages'),
                'intro' => 'You have unread messages from <strong>' . e($who) . '</strong> that have been waiting a little while.',
                'details' => $details,
                'cta' => ['text' => 'Open chat', 'url' => function_exists('frontendUrl') ? frontendUrl('/v2/chat') : url('/')],
                'signoff' => 'You only get this when messages stay unread - mute a chat inside Archilance to stop hearing about it.',
            ], 'chat_message');

            DB::table('chat_presence')->updateOrInsert(['user_id' => $userId], ['last_digest_at' => $now]);
            $sent++;
        }

        return $sent;
    }
}
