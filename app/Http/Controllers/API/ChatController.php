<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Chat;
use App\Models\ChatAttachment;
use App\Models\ChatReaction;
use App\Models\ChatReadStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\Services\OneDriveService;
use GuzzleHttp\Client;



class ChatController extends Controller
{
    // Send Message
    public function store(Request $request)
    {
        $request->validate([
            'receiver_id'   => 'required|exists:users,id',
            'message'       => 'nullable|string',
            'attachments.*' => 'nullable|file|max:10240',
            'attachments.*' => 'nullable|file|max:10240',
        ]);
    
        $senderId = Auth::id();
    
    
        $senderId = Auth::id();
    
        $chat = Chat::create([
            'sender_id'   => $senderId,
            'receiver_id' => $request->receiver_id,
            'message'     => $request->message,
            'reply_to'    => $request->reply_to
        ]);
    
    
        // Handle attachments
        if ($request->hasFile('attachments')) {
    
    
            foreach ($request->file('attachments') as $file) {
    
    
                $path = 'chat_attachments/' . $chat->id . '/' . uniqid() . '_' . $file->getClientOriginalName();
    
    
                app(OneDriveService::class)->upload(
                    $path,
                    file_get_contents($file->getRealPath())
                );
    
    
                ChatAttachment::create([
                    'chat_id'   => $chat->id,
                    'user_id'   => $senderId,
                    'file_path' => $path,
                    'file_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'file_name' => $file->getClientOriginalName(),
                ]);
            }
        }
    
        /*
        |--------------------------------------------------------------------------
        | Intelligent Email Notification Logic
        |--------------------------------------------------------------------------
        */
    
    
        /*
        |--------------------------------------------------------------------------
        | Intelligent Email Notification Logic
        |--------------------------------------------------------------------------
        */
    
        $receiver = User::find($request->receiver_id);
        $shouldSendEmail = true;
    
        // 1️⃣ Check if unread messages already exist
        $unreadExists = Chat::where('sender_id', $senderId)
            ->where('receiver_id', $receiver->id)
            ->whereHas('readStatus', function ($q) use ($receiver) {
                $q->where('receiver_id', $receiver->id)
                  ->where('is_read', false);
            })
            ->exists();
    
        if ($unreadExists) {
            $shouldSendEmail = false;
        }
    
        // 2️⃣ Check if receiver is online (optional but good)
        if ($receiver->last_seen && \Carbon\Carbon::parse($receiver->last_seen)->diffInMinutes(now()) < 2) {
            $shouldSendEmail = false;
        }
    
        // 3️⃣ Prevent multiple emails in short time
        if ($receiver->last_message_email_sent_at) {
    
            $minutes = \Carbon\Carbon::parse($receiver->last_message_email_sent_at)
                ->diffInMinutes(now());
    
            if ($minutes < 10) {
                $shouldSendEmail = false;
            }
        }
    
        // Chat v2: once its database update is applied, a chat the receiver has MUTED stays silent (no bell,
        // no push, no email), and emails come only from the unread digest (App\Services\ChatDigest) instead of
        // one per message. Before that update this block changes nothing.
        $chatV2 = \App\Support\ChatSchema::ready();
        $mutedByReceiver = $chatV2 && \App\Services\ChatCore::isMutedFor($receiver->id, 'user', $senderId);
        if ($chatV2) {
            $shouldSendEmail = false;
        }

        // In-app notification (always) + professional email (respecting the smart gating above)
        if (!$mutedByReceiver) {
            chatMessageNotification($receiver, Auth::user(), $request->message, $shouldSendEmail);
        }

        // Send email
        /*
        if ($shouldSendEmail) {
    
            $receiver_email = $receiver->email;
            $sender_name    = Auth::user()->name;
            $message_text   = $request->message;
    
            \Mail::send(
                'mails.new-message',
                compact('message_text'),
                function ($message) use ($receiver_email, $sender_name) {
    
                    $message
                        ->from("info@archilance.net", $sender_name)
                        ->to($receiver_email)
                        ->subject($sender_name . ' messaged you - Archilance LLC');
                }
            );
        } */
        
         /*
        |--------------------------------------------------------------------------
        | Send Push Notification (FCM)
        |--------------------------------------------------------------------------
        */
        
        
        
        if (!$mutedByReceiver && !empty($receiver->fcm_token)) {
        
            $senderName = Auth::user()->name;
        
            $title = "New Message from {$senderName}";
            $body  = $request->message 
                        ? $request->message 
                        : "📎 Sent you an attachment";
        
            $this->sendFcmNotification($receiver->fcm_token, $title, $body );
        }

        // Record when we actually emailed, so the 10-min throttle above stays meaningful
        if ($shouldSendEmail) {
            $receiver->update([
                'last_message_email_sent_at' => now()
            ]);
        }
    
        return response()->json([
            'message' => 'Message sent.',
            'chat' => $chat->load('attachments')
        ]);
    }


    protected function sendFcmNotification($token, $title, $body, $dataPayload = [])
    {
        try {
    
            \Log::info('FCM START');
    
            $serviceAccountPath = storage_path('firebase/firebase-key.json');
            $jsonKey = json_decode(file_get_contents($serviceAccountPath), true);
    
            $client = new Client();
    
            /*
            |--------------------------------------------------------------------------
            | 1. Generate OAuth Token
            |--------------------------------------------------------------------------
            */
            $now = time();
            $jwtHeader = base64_encode(json_encode([
                'alg' => 'RS256',
                'typ' => 'JWT'
            ]));
    
            $jwtClaim = base64_encode(json_encode([
                'iss' => $jsonKey['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'exp' => $now + 3600,
                'iat' => $now
            ]));
    
            $unsignedJwt = $jwtHeader . '.' . $jwtClaim;
    
            openssl_sign($unsignedJwt, $signature, $jsonKey['private_key'], 'sha256');
            $jwt = $unsignedJwt . '.' . base64_encode($signature);
    
            $response = $client->post('https://oauth2.googleapis.com/token', [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]
            ]);
    
            $accessToken = json_decode((string) $response->getBody(), true)['access_token'];
    
            \Log::info('FCM TOKEN GENERATED');
    
            /*
            |--------------------------------------------------------------------------
            | 2. Send Notification
            |--------------------------------------------------------------------------
            */
    
            $projectId = $jsonKey['project_id'];
    
            $fcmResponse = $client->post(
                "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send",
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'message' => [
                            'token' => $token,
                    
                            'notification' => [
                                'title' => $title,
                                'body'  => $body,
                            ],
                    
                            'android' => [
                                'notification' => [
                                    'channel_id' => 'archilance_notification_channel',
                                    'sound' => 'notification_sound',
                                ],
                            ],
                    
                            // 👇 ADD THIS FOR iOS
                            'apns' => [
                                'payload' => [
                                    'aps' => [
                                        'sound' => 'notification_sound.wav', // 👈 MUST include extension
                                    ],
                                ],
                            ],
                    
                            'data' => array_merge([
                                'type' => (string) 'normal',
                                'notify_type'=> (string) 'chat',
                                'chat_type' => (string) 'user',
                                'ref_id' => (string) Auth::user()->id,
                                'message' => (string) $body
                            ], collect($dataPayload)->map(fn($v) => (string) $v)->toArray()),
                        ]
                    ]
                ]
            );
    
            \Log::info('FCM SENT SUCCESS', [
                'response' => (string) $fcmResponse->getBody()
            ]);
    
        } catch (\Exception $e) {
            \Log::error('FCM ERROR: ' . $e->getMessage());
        }
    }

    // Update message
    public function update(Request $request, $id)
    {
        $chat = Chat::findOrFail($id);

        if ($chat->sender_id != Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'message'           => 'nullable|string',
            'attachments.*'     => 'nullable|file|max:10240',
            'delete_attachments'=> 'nullable|array',
        ]);

        $chat->update([
            'message' => $request->message,
        ]);

       // Handle new file uploads
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {

                $path = 'chat_attachments/' . $chat->id . '/' . uniqid() . '_' . $file->getClientOriginalName();

                // Upload to OneDrive
                app(OneDriveService::class)->upload(
                    $path,
                    file_get_contents($file->getRealPath())
                );

                ChatAttachment::create([
                    'chat_id'   => $chat->id,
                    'user_id'   => Auth::id(),
                    'file_path' => $path,
                    'file_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                    'file_name' => $file->getClientOriginalName(),
                ]);
            }
        }

        // Handle deletions of existing attachments
        if ($request->has('delete_attachments')) {
            foreach ($request->delete_attachments as $attachmentId) {

                $attachment = ChatAttachment::where('chat_id', $chat->id)
                    ->where('id', $attachmentId)
                    ->first();

                if ($attachment) {
                    // Delete from OneDrive
                    app(OneDriveService::class)->delete($attachment->file_path);

                    // Delete DB record
                    $attachment->delete();
                }
            }
        }

        return response()->json(['message' => 'Message updated.', 'chat' => $chat->load('attachments')]);
    }

    // Add reaction
    public function addReaction(Request $request)
    {
        $request->validate([
            'chat_id' => 'required|exists:chats,id',
            'reaction' => 'required|string',
        ]);

        $reaction = ChatReaction::updateOrCreate(
            [
                'chat_id' => $request->chat_id,
                'user_id' => Auth::id(),
            ],
            [
                'reaction' => $request->reaction,
            ]
        );

        return response()->json(['message' => 'Reaction updated.', 'reaction' => $reaction]);
    }


    public function removeReaction(Request $request)
    {
        $request->validate([
            'chat_id' => 'required|exists:chats,id',
        ]);

        $deleted = ChatReaction::where('chat_id', $request->chat_id)
            ->where('user_id', Auth::id())
            ->delete();

        if ($deleted) {
            return response()->json(['message' => 'Reaction removed.']);
        } else {
            return response()->json(['message' => 'No reaction found to remove.'], 404);
        }
    }

    // Mark message as read
    public function markRead(Request $request)
    {
        $request->validate([
            'message_id' => 'required|exists:chats,id',
        ]);

        $read = ChatReadStatus::updateOrCreate(
            [
                'message_id' => $request->message_id,
                'receiver_id' => Auth::id(),
            ],
            [
                'is_read' => true,
            ]
        );

        return response()->json(['message' => 'Message marked as read.', 'read_status' => $read]);
    }

    public function bulkMarkRead(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $authId = Auth::id();

        // Get all messages sent by the other user to me
        $messages = Chat::where('sender_id', $request->user_id)
            ->where('receiver_id', $authId)
            ->pluck('id');

        if ($messages->isEmpty()) {
            return response()->json(['message' => 'No messages to update.']);
        }

        foreach ($messages as $messageId) {
            // Check if already marked as read
            $existing = ChatReadStatus::where('message_id', $messageId)
                ->where('receiver_id', $authId)
                ->first();

            if ($existing) {
                if (!$existing->is_read) {
                    $existing->is_read = true;
                    $existing->save();
                }
            } else {
                ChatReadStatus::create([
                    'message_id' => $messageId,
                    'receiver_id' => $authId,
                    'is_read' => true,
                ]);
            }
        }

        return response()->json(['message' => 'All messages marked as read.']);
    }


    // Fetch conversation between authenticated user & other user
    public function getConversation(Request $request, $userId)
    {
        $authId = Auth::id();

        $query = Chat::where(function ($q) use ($authId, $userId) {
                $q->where('sender_id', $authId)->where('receiver_id', $userId);
            })
            ->orWhere(function ($q) use ($authId, $userId) {
                $q->where('sender_id', $userId)->where('receiver_id', $authId);
            })
            ->with(['sender', 'receiver', 'attachments', 'reactions', 'readStatuses', 'parent', 'parent.sender'])
            ->orderBy('created_at', 'desc'); // Order latest first

        // paginate: default 10 per page
        $chats = $query->paginate(10);

        // Reverse inside page
        $chats->getCollection()->transform(function ($chat) {
            return $chat;
        });

        $chats->setCollection($chats->getCollection()->reverse()->values());

        return response()->json($chats);
    }

    public function unreadCount()
    {
        $authId = Auth::id();

        // Find all chat messages where this user is the receiver
        $query = Chat::where('receiver_id', $authId)
            ->whereDoesntHave('readStatuses', function($q) use ($authId) {
                $q->where('receiver_id', $authId)->where('is_read', true);
            });

        $count = $query->count();

        return response()->json([
            'unread_count' => $count
        ]);
    }

    public function getChatUsersList(Request $request)
    {
        $authId = Auth::id();
        $authUser = Auth::user();
        $role = $authUser->user_role; // 2=admin, 3=employee, 4=customer

        $userQuery = \App\Models\User::query()->where('id', '!=', $authId); // exclude self

        // Apply filters based on role
        if ($role == 2) {
            // Admin - can filter between employees and customers
            if ($request->has('type') && in_array($request->type, ['employee', 'customer'])) {
                $filterRole = ($request->type == 'employee') ? 3 : 4;
                $userQuery->where('user_role', $filterRole);
            }
        } elseif ($role == 3) {
            // Employee: only Admin & other Employees
            $userQuery->whereIn('user_role', [2, 3]);
        } elseif ($role == 4) {
            // Customer: only Admin
            $userQuery->where('user_role', 2);
        }

        // Preload unread count and last message via subqueries
        $users = $userQuery->get()->map(function ($user) use ($authId) {

            // Unread count for each user
            $unreadCount = Chat::where('sender_id', $user->id)
                ->where('receiver_id', $authId)
                ->whereDoesntHave('readStatuses', function ($q) use ($authId) {
                    $q->where('receiver_id', $authId)->where('is_read', true);
                })->count();

            // Get last message (from either direction)
            $lastMessage = Chat::where(function ($q) use ($authId, $user) {
                    $q->where('sender_id', $authId)->where('receiver_id', $user->id);
                })->orWhere(function ($q) use ($authId, $user) {
                    $q->where('sender_id', $user->id)->where('receiver_id', $authId);
                })->orderBy('created_at', 'desc')->first();

            return [
                'id' => $user->id,
                'name' => $user->name,
                'profile_pic' => $user->profile_pic,
                'user_role' => $user->user_role,
                'unread_count' => $unreadCount,
                'last_message' => $lastMessage ? $this->lastMessagePreview($lastMessage) : null,
                'last_message_at' => $lastMessage ? $lastMessage->created_at : $user->created_at,
            ];
        });

        // Now sort by last message datetime (latest first)
        $users = $users->sortByDesc('last_message_at')->values();

        return response()->json([
            'users' => $users
        ]);
    }

    /**
     * Text for the "latest message" line of the people list. A message that is only a file / voice note has no text,
     * so it used to come back empty and the app said "no message"; now it says what was sent.
     */
    private function lastMessagePreview(Chat $chat): string
    {
        $text = trim((string) $chat->message);
        if ($text !== '') {
            return \Str::limit($text, 30, '...');
        }

        try {
            $type = strtolower((string) optional($chat->attachments()->first())->file_type);
        } catch (\Throwable $e) {
            return '';
        }
        if ($type === '' && !$chat->attachments()->exists()) {
            return '';
        }
        if (str_contains($type, 'audio') || str_contains($type, 'voice')) {
            return 'Voice message';
        }
        if (str_contains($type, 'image') || str_contains($type, 'photo')) {
            return 'Photo';
        }
        if (str_contains($type, 'video')) {
            return 'Video';
        }

        return 'Attachment';
    }

    public function deleteMessage($id)
    {
        $chat = Chat::findOrFail($id);
        $authUser = Auth::user();

        // Permission check
        if ($chat->sender_id != $authUser->id && $authUser->user_role != 2) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $chat->delete();

        return response()->json(['message' => 'Message deleted successfully.']);
    }




}
