<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\ChatGroup;
use App\Models\ChatGroupMember;
use App\Models\ChatPeerSetting;
use App\Models\User;
use App\Services\ChatCore;
use App\Services\ChatStorage;
use App\Support\ChatSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Chat groups: create, rename, picture, add / remove people, admins, leave, delete.
 * Staff only (admins, employees, executives, supervisors) - customers never appear in a group.
 * Every membership change leaves a one-line system message in the group so nobody is surprised.
 */
class ChatGroupController extends Controller
{
    private function unsupported()
    {
        return response()->json(['supported' => false, 'message' => 'Chat v2 needs a database update (System settings -> Update database).'], 503);
    }

    private function adminOf(ChatGroup $group, int $userId): bool
    {
        $m = ChatCore::membership($group->id, $userId);

        return $m && $m->role === 'admin';
    }

    private function system(ChatGroup $group, int $actorId, string $text): Chat
    {
        return Chat::create(['sender_id' => $actorId, 'receiver_id' => null, 'group_id' => $group->id, 'message' => $text, 'is_system' => true]);
    }

    private function firstName(User $u): string
    {
        return explode(' ', trim($u->name))[0];
    }

    /** POST /api/chat/v2/groups  (multipart: name, description, member_ids[], avatar) */
    public function store(Request $request, ChatStorage $storage)
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }

        $me = Auth::user();
        if (!ChatCore::isStaff($me)) {
            return response()->json(['message' => 'Groups are for the internal team.'], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|min:1|max:120',
            'description' => 'nullable|string|max:500',
            'member_ids' => 'nullable|array|max:300',
            'member_ids.*' => 'integer',
            'avatar' => 'nullable|image|max:5120',
        ]);

        $memberIds = collect($data['member_ids'] ?? [])->map(fn ($i) => (int) $i)->unique()->reject(fn ($i) => $i === $me->id)->values();
        $people = User::whereIn('id', $memberIds)->whereIn('user_role', ChatCore::STAFF_ROLES)->get(['id', 'name']);

        $group = DB::transaction(function () use ($data, $me, $people) {
            $group = ChatGroup::create(['name' => trim($data['name']), 'description' => $data['description'] ?? null, 'created_by' => $me->id]);

            ChatGroupMember::create(['group_id' => $group->id, 'user_id' => $me->id, 'role' => 'admin', 'color' => 0, 'joined_at' => now()]);
            foreach ($people as $p) {
                ChatGroupMember::create([
                    'group_id' => $group->id, 'user_id' => $p->id, 'role' => 'member', 'added_by' => $me->id,
                    'color' => ChatCore::nextColor($group->id), 'joined_at' => now(),
                ]);
            }
            $this->system($group, $me->id, $this->firstName($me) . ' created the group');

            return $group;
        });

        if ($request->hasFile('avatar')) {
            $this->storeAvatar($group, $request->file('avatar'), $storage);
        }

        return response()->json(['message' => 'Group created.', 'group' => $this->present($group->fresh(), $me->id)], 201);
    }

    /** GET /api/chat/v2/groups/{id} */
    public function show($id)
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }

        $group = ChatGroup::find($id);
        if (!$group || !ChatCore::membership($group->id, Auth::id())) {
            return response()->json(['message' => 'You are not in this group.'], 403);
        }

        return response()->json(['group' => $this->present($group, Auth::id())]);
    }

    /** POST /api/chat/v2/groups/{id}  (name, description, avatar) - group admins only */
    public function update(Request $request, $id, ChatStorage $storage)
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }

        $me = Auth::user();
        $group = ChatGroup::findOrFail($id);
        if (!$this->adminOf($group, $me->id)) {
            return response()->json(['message' => 'Only group admins can change this.'], 403);
        }

        $data = $request->validate([
            'name' => 'nullable|string|min:1|max:120',
            'description' => 'nullable|string|max:500',
            'avatar' => 'nullable|image|max:5120',
            'remove_avatar' => 'nullable|boolean',
        ]);

        if (isset($data['name']) && trim($data['name']) !== '' && trim($data['name']) !== $group->name) {
            $old = $group->name;
            $group->name = trim($data['name']);
            $this->system($group, $me->id, $this->firstName($me) . ' renamed the group from "' . $old . '" to "' . $group->name . '"');
        }
        if (array_key_exists('description', $data)) {
            $group->description = $data['description'];
        }
        if (!empty($data['remove_avatar']) && $group->avatar_path) {
            $storage->remove($group->avatar_path);
            $group->avatar_path = null;
        }
        $group->save();

        if ($request->hasFile('avatar')) {
            $this->storeAvatar($group, $request->file('avatar'), $storage);
            $this->system($group, $me->id, $this->firstName($me) . ' changed the group picture');
        }

        return response()->json(['message' => 'Saved.', 'group' => $this->present($group->fresh(), $me->id)]);
    }

    /** POST /api/chat/v2/groups/{id}/members {user_ids[]} */
    public function addMembers(Request $request, $id)
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }

        $me = Auth::user();
        $group = ChatGroup::findOrFail($id);
        if (!$this->adminOf($group, $me->id)) {
            return response()->json(['message' => 'Only group admins can add people.'], 403);
        }
        $data = $request->validate(['user_ids' => 'required|array|min:1|max:100', 'user_ids.*' => 'integer']);

        $people = User::whereIn('id', $data['user_ids'])->whereIn('user_role', ChatCore::STAFF_ROLES)->get(['id', 'name']);
        $added = [];

        foreach ($people as $p) {
            $row = ChatGroupMember::where('group_id', $group->id)->where('user_id', $p->id)->first();
            if ($row && !$row->left_at) {
                continue; // already in
            }
            if ($row) { // coming back after leaving / being removed
                $row->update(['left_at' => null, 'role' => 'member', 'added_by' => $me->id, 'joined_at' => now(),
                    'last_read_id' => (int) DB::table('chats')->where('group_id', $group->id)->max('id')]);
            } else {
                ChatGroupMember::create([
                    'group_id' => $group->id, 'user_id' => $p->id, 'role' => 'member', 'added_by' => $me->id,
                    'color' => ChatCore::nextColor($group->id), 'joined_at' => now(),
                    'last_read_id' => (int) DB::table('chats')->where('group_id', $group->id)->max('id'),
                ]);
            }
            $added[] = $this->firstName($p);
        }

        if ($added) {
            $this->system($group, $me->id, $this->firstName($me) . ' added ' . $this->joinNames($added));
        }

        return response()->json(['message' => $added ? 'Added.' : 'Nobody to add.', 'group' => $this->present($group->fresh(), $me->id)]);
    }

    /** DELETE /api/chat/v2/groups/{id}/members/{userId} - remove someone (admins) or leave (yourself) */
    public function removeMember($id, $userId)
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }

        $me = Auth::user();
        $group = ChatGroup::findOrFail($id);
        $target = ChatCore::membership($group->id, (int) $userId);
        if (!$target) {
            return response()->json(['message' => 'That person is not in the group.'], 404);
        }

        $self = (int) $userId === (int) $me->id;
        if (!$self && !$this->adminOf($group, $me->id)) {
            return response()->json(['message' => 'Only group admins can remove people.'], 403);
        }

        $user = User::withTrashed()->find($userId);
        $target->update(['left_at' => now()]);

        // A group is never left without an admin: the longest-standing member is promoted.
        $remaining = ChatGroupMember::where('group_id', $group->id)->whereNull('left_at')->orderBy('joined_at')->get();
        if ($remaining->isEmpty()) {
            $group->delete();

            return response()->json(['message' => 'Group closed (it had no members left).', 'closed' => true]);
        }
        if (!$remaining->contains('role', 'admin')) {
            $remaining->first()->update(['role' => 'admin']);
        }

        $this->system($group, $me->id, $self ? $this->firstName($me) . ' left' : $this->firstName($me) . ' removed ' . ($user ? $this->firstName($user) : 'a member'));

        return response()->json(['message' => $self ? 'You left the group.' : 'Removed.', 'closed' => false]);
    }

    /** POST /api/chat/v2/groups/{id}/members/{userId}/role {role: admin|member} */
    public function setRole(Request $request, $id, $userId)
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }

        $me = Auth::user();
        $group = ChatGroup::findOrFail($id);
        if (!$this->adminOf($group, $me->id)) {
            return response()->json(['message' => 'Only group admins can do this.'], 403);
        }
        $data = $request->validate(['role' => 'required|in:admin,member']);
        $target = ChatCore::membership($group->id, (int) $userId);
        if (!$target) {
            return response()->json(['message' => 'That person is not in the group.'], 404);
        }
        if ($data['role'] === 'member' && ChatGroupMember::where('group_id', $group->id)->whereNull('left_at')->where('role', 'admin')->count() <= 1 && $target->role === 'admin') {
            return response()->json(['message' => 'A group needs at least one admin.'], 422);
        }
        $target->update(['role' => $data['role']]);
        $user = User::withTrashed()->find($userId);
        $this->system($group, $me->id, $this->firstName($me) . ($data['role'] === 'admin' ? ' made ' : ' removed admin from ') . ($user ? $this->firstName($user) : 'a member') . ($data['role'] === 'admin' ? ' an admin' : ''));

        return response()->json(['message' => 'Updated.', 'group' => $this->present($group->fresh(), $me->id)]);
    }

    /** DELETE /api/chat/v2/groups/{id} - admins close the group for everyone */
    public function destroy($id)
    {
        if (!ChatSchema::ready()) {
            return $this->unsupported();
        }

        $group = ChatGroup::findOrFail($id);
        if (!$this->adminOf($group, Auth::id())) {
            return response()->json(['message' => 'Only group admins can delete the group.'], 403);
        }
        $group->delete();
        ChatPeerSetting::where('peer_type', 'group')->where('peer_id', $group->id)->delete();

        return response()->json(['message' => 'Group deleted.']);
    }

    /** GET (signed) /api/chat/v2/groups/{group}/avatar */
    public function avatar($group, ChatStorage $storage)
    {
        $g = ChatGroup::withTrashed()->find($group);
        if (!$g || !$g->avatar_path) {
            abort(404);
        }

        try {
            return redirect()->away($storage->downloadUrl($g->avatar_path))->header('Cache-Control', 'private, max-age=3000');
        } catch (\Throwable $e) {
            abort(404);
        }
    }

    /* ----------------------------------------------------------------------------------------- */

    private function storeAvatar(ChatGroup $group, $file, ChatStorage $storage): void
    {
        try {
            $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            $path = 'chat_avatars/' . $group->id . '/' . Str::uuid() . '.' . preg_replace('/[^a-z0-9]/', '', $ext);
            $storage->putSmall($path, file_get_contents($file->getRealPath()));
            if ($group->avatar_path) {
                $storage->remove($group->avatar_path);
            }
            $group->update(['avatar_path' => $path]);
        } catch (\Throwable $e) {
            \Log::warning('Group avatar upload failed: ' . $e->getMessage());
        }
    }

    private function joinNames(array $names): string
    {
        if (count($names) <= 1) {
            return $names[0] ?? '';
        }
        $last = array_pop($names);

        return implode(', ', $names) . ' and ' . $last;
    }

    /** The group, its people (each with their colour, role and online state) and my own standing. */
    private function present(ChatGroup $g, int $viewerId): array
    {
        $members = ChatGroupMember::where('group_id', $g->id)->whereNull('left_at')->get();
        $users = User::withTrashed()->whereIn('id', $members->pluck('user_id'))->get(['id', 'name', 'profile_pic', 'employee_type', 'employee_team'])->keyBy('id');
        $presence = DB::table('chat_presence')->whereIn('user_id', $members->pluck('user_id'))->pluck('last_seen_at', 'user_id');
        $mine = $members->firstWhere('user_id', $viewerId);
        $setting = ChatPeerSetting::where('user_id', $viewerId)->where('peer_type', 'group')->where('peer_id', $g->id)->first();

        return [
            'id' => (int) $g->id,
            'name' => $g->name,
            'description' => $g->description,
            'avatar_url' => $g->avatar_path ? URL::temporarySignedRoute('chat.v2.groupavatar', now()->addHours(12), ['group' => $g->id, 'v' => optional($g->updated_at)->timestamp ?? 0], absolute: false) : null,
            'created_by' => $g->created_by ? (int) $g->created_by : null,
            'created_at' => ChatCore::iso($g->created_at),
            // Colours of EVERY past and present member, so old messages from someone who has since left keep their colour.
            'member_colors' => ChatGroupMember::where('group_id', $g->id)->pluck('color', 'user_id')->map(fn ($c) => (int) $c)->all(),
            'my_role' => $mine?->role,
            'muted' => $setting ? $setting->isMuted() : false,
            'pinned' => (bool) ($setting && $setting->pinned_at),
            'members' => $members->map(function ($m) use ($users, $presence) {
                $u = $users->get($m->user_id);
                $seen = $presence[$m->user_id] ?? null;

                return [
                    'user_id' => (int) $m->user_id,
                    'name' => $u?->name ?? 'Deleted user',
                    'profile_pic' => $u?->profile_pic,
                    'employee_type' => $u?->employee_type,
                    'employee_team' => $u?->employee_team,
                    'role' => $m->role,
                    'color' => (int) $m->color,
                    'online' => $seen ? \Illuminate\Support\Carbon::parse($seen)->gt(now()->subMinutes(2)) : false,
                    'joined_at' => ChatCore::iso($m->joined_at),
                ];
            })->sortBy([fn ($a, $b) => ($a['role'] === 'admin' ? 0 : 1) <=> ($b['role'] === 'admin' ? 0 : 1), fn ($a, $b) => strcasecmp($a['name'], $b['name'])])->values()->all(),
        ];
    }
}
