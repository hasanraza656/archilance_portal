<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;

/**
 * Push notifications through Firebase Cloud Messaging.
 *
 * Same message format the existing chat push uses (so the Android app handles it unchanged), but the OAuth
 * access token is cached for ~50 minutes instead of being requested for every single push - which matters
 * for group messages that notify many people at once. Never throws: a failed push must not fail a message.
 */
class FcmService
{
    public function send(?string $token, string $title, string $body, array $data = []): bool
    {
        if (!$token) {
            return false;
        }

        try {
            $key = $this->serviceAccount();
            if (!$key) {
                return false;
            }
            $client = new Client(['timeout' => 15]);
            $access = $this->accessToken($client, $key);

            $client->post("https://fcm.googleapis.com/v1/projects/{$key['project_id']}/messages:send", [
                'headers' => ['Authorization' => 'Bearer ' . $access, 'Content-Type' => 'application/json'],
                'json' => [
                    'message' => [
                        'token' => $token,
                        'notification' => ['title' => $title, 'body' => $body],
                        'android' => ['notification' => ['channel_id' => 'archilance_notification_channel', 'sound' => 'notification_sound']],
                        'apns' => ['payload' => ['aps' => ['sound' => 'notification_sound.wav']]],
                        'data' => array_merge(
                            ['type' => 'normal', 'notify_type' => 'chat', 'chat_type' => 'user', 'message' => $body],
                            collect($data)->map(fn ($v) => (string) $v)->all()
                        ),
                    ],
                ],
            ]);

            return true;
        } catch (\Throwable $e) {
            \Log::warning('FCM push failed: ' . $e->getMessage());

            return false;
        }
    }

    private function serviceAccount(): ?array
    {
        $path = storage_path('firebase/firebase-key.json');
        if (!is_file($path)) {
            return null;
        }

        return json_decode((string) file_get_contents($path), true) ?: null;
    }

    private function accessToken(Client $client, array $key): string
    {
        return Cache::remember('fcm_access_token', now()->addMinutes(50), function () use ($client, $key) {
            $now = time();
            $b64 = fn ($v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');
            $unsigned = $b64(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $b64([
                'iss' => $key['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'exp' => $now + 3600,
                'iat' => $now,
            ]);
            openssl_sign($unsigned, $signature, $key['private_key'], 'sha256');
            $jwt = $unsigned . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

            $res = $client->post('https://oauth2.googleapis.com/token', [
                'form_params' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt],
            ]);

            return json_decode((string) $res->getBody(), true)['access_token'];
        });
    }
}
