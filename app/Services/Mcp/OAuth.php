<?php

namespace App\Services\Mcp;

use App\Models\User;
use App\Support\McpSchema;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * OAuth 2.1 (authorization code + PKCE, refresh tokens, dynamic client registration) for the Claude connector.
 *
 * Everything secret - codes, access tokens, refresh tokens - is shown once and stored only as a SHA-256 hash.
 * Only administrators (user_role 2) can ever obtain a token, and the check is repeated on every MCP call, so
 * demoting or deleting an admin cuts Claude off immediately.
 */
final class OAuth
{
    public const ADMIN_ROLE = 2;
    private const CODE_TTL_MINUTES = 10;

    /* ------------------------------------------------------------------ small helpers */

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public static function token(string $prefix): string
    {
        return $prefix . rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');
    }

    /** Public address of this API (no trailing slash). Works at a domain root and in a sub-folder. */
    public static function base(Request $request): string
    {
        $forced = (string) (McpSchema::settings()['public_url'] ?? '');
        if ($forced !== '') {
            return rtrim($forced, '/');
        }
        $root = rtrim($request->root(), '/');
        $host = parse_url($root, PHP_URL_HOST) ?: '';
        if (!in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            $root = preg_replace('#^http://#i', 'https://', $root); // the connector is HTTPS-only
        }

        return $root;
    }

    public static function clientIp(Request $request): string
    {
        $fwd = (string) $request->header('X-Forwarded-For', '');
        $first = trim(explode(',', $fwd)[0] ?? '');

        return $first !== '' ? mb_substr($first, 0, 45) : (string) $request->ip();
    }

    public static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /* ------------------------------------------------------------------ redirect rules */

    /** Where Claude may be sent after login: https on an allowed host, or a local app (loopback) on any port. */
    public static function redirectAllowed(string $uri): bool
    {
        if (mb_strlen($uri) > 500 || str_contains($uri, '#')) {
            return false;
        }
        $p = parse_url($uri);
        if (!$p || empty($p['scheme']) || empty($p['host'])) {
            return false;
        }
        $scheme = strtolower($p['scheme']);
        $host = strtolower($p['host']);
        if (isset($p['user']) || isset($p['pass'])) {
            return false;
        }
        $loopback = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        if ($scheme === 'http') {
            return $loopback;
        }
        if ($scheme !== 'https') {
            return false;
        }
        $allowed = array_map('strtolower', (array) (McpSchema::settings()['allowed_redirect_hosts'] ?? []));

        return $loopback || in_array($host, $allowed, true);
    }

    /* ------------------------------------------------------------------ clients */

    /** @return array{client_id:string,client_name:string,redirect_uris:array}|array{error:string,error_description:string} */
    public static function registerClient(array $meta, string $ip): array
    {
        $uris = $meta['redirect_uris'] ?? null;
        if (!is_array($uris) || !$uris || count($uris) > 10) {
            return ['error' => 'invalid_redirect_uri', 'error_description' => 'redirect_uris must list 1-10 URLs.'];
        }
        foreach ($uris as $u) {
            if (!is_string($u) || !self::redirectAllowed($u)) {
                return ['error' => 'invalid_redirect_uri', 'error_description' => 'That redirect URI is not allowed for this server.'];
            }
        }
        $name = trim(strip_tags((string) ($meta['client_name'] ?? 'Claude')));
        $name = mb_substr($name !== '' ? $name : 'Claude', 0, 120);
        $clientId = 'mcpc_' . bin2hex(random_bytes(16));

        DB::table('mcp_clients')->insert([
            'client_id' => $clientId,
            'client_name' => $name,
            'redirect_uris' => json_encode(array_values(array_unique($uris))),
            'registered_ip' => mb_substr($ip, 0, 45),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Housekeeping: apps that registered but never signed in.
        try {
            DB::table('mcp_clients')
                ->where('created_at', '<', Carbon::now()->subDays(30))
                ->whereNull('last_used_at')
                ->whereNotIn('client_id', DB::table('mcp_tokens')->select('client_id'))
                ->delete();
            DB::table('mcp_auth_codes')->where('expires_at', '<', Carbon::now()->subDay())->delete();
            DB::table('mcp_tokens')->where(fn ($q) => $q->whereNotNull('revoked_at')->where('revoked_at', '<', Carbon::now()->subDays(30)))
                ->orWhere(fn ($q) => $q->where('refresh_expires_at', '<', Carbon::now()->subDays(30)))->delete();
        } catch (\Throwable $e) {
            // not important
        }

        return ['client_id' => $clientId, 'client_name' => $name, 'redirect_uris' => array_values(array_unique($uris))];
    }

    public static function client(string $clientId): ?object
    {
        return DB::table('mcp_clients')->where('client_id', $clientId)->first();
    }

    public static function clientHasRedirect(object $client, string $uri): bool
    {
        return in_array($uri, (array) json_decode((string) $client->redirect_uris, true), true);
    }

    /* ------------------------------------------------------------------ users */

    public static function findLoginUser(string $login): ?User
    {
        return User::where('email', $login)->orWhere('username', $login)->orWhere('phone', $login)->first();
    }

    /** Null when this account may use the connector, otherwise a short reason. */
    public static function whyNot(?User $user): ?string
    {
        if (!$user) {
            return 'account not found';
        }
        if ((int) $user->user_role !== self::ADMIN_ROLE) {
            return 'only administrators can connect Claude to the portal';
        }
        $allow = (array) (McpSchema::settings()['allowed_admin_emails'] ?? []);
        if ($allow && !in_array(strtolower((string) $user->email), $allow, true)) {
            return 'this administrator account has not been enabled for Claude access';
        }

        return null;
    }

    /* ------------------------------------------------------------------ authorization codes */

    public static function createCode(object $client, User $user, string $redirectUri, string $challenge, ?string $resource): string
    {
        $code = self::token('mcpa_');
        DB::table('mcp_auth_codes')->insert([
            'code_hash' => self::hash($code),
            'client_id' => $client->client_id,
            'user_id' => $user->id,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $challenge,
            'resource' => $resource ? mb_substr($resource, 0, 255) : null,
            'expires_at' => Carbon::now()->addMinutes(self::CODE_TTL_MINUTES),
            'created_at' => Carbon::now(),
        ]);

        return $code;
    }

    /**
     * Trades a code (+ PKCE verifier) for tokens.
     *
     * @return array<string,mixed> token response, or ['error'=>..., 'error_description'=>...]
     */
    public static function exchangeCode(string $code, string $clientId, string $redirectUri, string $verifier): array
    {
        $bad = fn (string $why) => ['error' => 'invalid_grant', 'error_description' => $why];

        $row = DB::table('mcp_auth_codes')->where('code_hash', self::hash($code))->first();
        if (!$row || $row->client_id !== $clientId) {
            return $bad('Unknown authorization code.');
        }
        if (Carbon::parse($row->expires_at)->isPast()) {
            return $bad('The authorization code has expired.');
        }
        // Single use, claimed atomically: two racing requests cannot both win.
        $claimed = DB::table('mcp_auth_codes')->where('id', $row->id)->whereNull('used_at')->update(['used_at' => Carbon::now()]);
        if ($claimed !== 1) {
            // A replayed code: treat every token this sign-in produced as compromised.
            DB::table('mcp_tokens')->where('client_id', $clientId)->where('user_id', $row->user_id)->where('created_at', '>=', Carbon::parse($row->created_at))->whereNull('revoked_at')->update(['revoked_at' => Carbon::now()]);

            return $bad('The authorization code was already used.');
        }
        if (!hash_equals((string) $row->redirect_uri, $redirectUri)) {
            return $bad('redirect_uri does not match.');
        }
        if (strlen($verifier) < 43 || strlen($verifier) > 128 || !hash_equals((string) $row->code_challenge, self::b64url(hash('sha256', $verifier, true)))) {
            return $bad('PKCE verification failed.');
        }

        $user = User::find($row->user_id);
        if ($why = self::whyNot($user)) {
            return ['error' => 'access_denied', 'error_description' => ucfirst($why) . '.'];
        }

        return self::issue($user, $clientId);
    }

    /** @return array<string,mixed> */
    private static function issue(User $user, string $clientId, ?int $reuseRowId = null): array
    {
        $cfg = McpSchema::settings();
        $ttl = (int) ($cfg['access_ttl_minutes'] ?? 60);
        $refreshDays = (int) ($cfg['refresh_ttl_days'] ?? 30);
        $access = self::token('mcp_at_');
        $refresh = self::token('mcp_rt_');
        $now = Carbon::now();

        $fields = [
            'access_hash' => self::hash($access),
            'refresh_hash' => self::hash($refresh),
            'access_expires_at' => $now->copy()->addMinutes($ttl),
            'refresh_expires_at' => $now->copy()->addDays($refreshDays),
            'updated_at' => $now,
        ];
        if ($reuseRowId) {
            DB::table('mcp_tokens')->where('id', $reuseRowId)->update($fields);
        } else {
            DB::table('mcp_tokens')->insert($fields + ['user_id' => $user->id, 'client_id' => $clientId, 'created_at' => $now]);
        }
        DB::table('mcp_clients')->where('client_id', $clientId)->update(['last_used_at' => $now]);

        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => $ttl * 60, 'refresh_token' => $refresh, 'scope' => 'mcp'];
    }

    /** @return array<string,mixed> */
    public static function refresh(string $refreshToken, string $clientId): array
    {
        $row = DB::table('mcp_tokens')->where('refresh_hash', self::hash($refreshToken))->first();
        if (!$row || $row->client_id !== $clientId || $row->revoked_at || !$row->refresh_expires_at || Carbon::parse($row->refresh_expires_at)->isPast()) {
            return ['error' => 'invalid_grant', 'error_description' => 'The refresh token is invalid or expired. Sign in again.'];
        }
        $user = User::find($row->user_id);
        if ($why = self::whyNot($user)) {
            return ['error' => 'invalid_grant', 'error_description' => ucfirst($why) . '.'];
        }
        // Rotation: the old refresh token dies the moment the new one is made (compare-and-swap so a race cannot fork it).
        $swapped = DB::table('mcp_tokens')->where('id', $row->id)->where('refresh_hash', $row->refresh_hash)->update(['refresh_hash' => null]);
        if ($swapped !== 1) {
            return ['error' => 'invalid_grant', 'error_description' => 'The refresh token was already used.'];
        }

        return self::issue($user, $clientId, (int) $row->id);
    }

    public static function revoke(string $token): void
    {
        $h = self::hash($token);
        DB::table('mcp_tokens')->whereNull('revoked_at')->where(fn ($q) => $q->where('access_hash', $h)->orWhere('refresh_hash', $h))
            ->update(['revoked_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
    }

    /* ------------------------------------------------------------------ bearer check on every MCP call */

    /** @return array{user:User, client_id:string, token_id:int}|null */
    public static function authenticate(string $bearer, Request $request): ?array
    {
        if ($bearer === '' || !str_starts_with($bearer, 'mcp_at_')) {
            return null;
        }
        $row = DB::table('mcp_tokens')->where('access_hash', self::hash($bearer))->whereNull('revoked_at')->first();
        if (!$row || Carbon::parse($row->access_expires_at)->isPast()) {
            return null;
        }
        $user = User::find($row->user_id);
        if (self::whyNot($user) !== null) {
            return null;
        }
        if (!$row->last_used_at || Carbon::parse($row->last_used_at)->lt(Carbon::now()->subMinute())) {
            DB::table('mcp_tokens')->where('id', $row->id)->update(['last_used_at' => Carbon::now(), 'last_ip' => self::clientIp($request)]);
        }

        return ['user' => $user, 'client_id' => $row->client_id, 'token_id' => (int) $row->id];
    }
}
