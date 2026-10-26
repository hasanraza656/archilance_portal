<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Services\Mcp\Audit;
use App\Services\Mcp\OAuth;
use App\Services\Mcp\Pages;
use App\Support\McpSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Discovery documents, client registration, the sign-in page and the token endpoint of the Claude connector.
 * Every route answers 404 until MCP_ENABLED=true, and 503 until the database update has been applied.
 */
class OAuthController extends Controller
{
    private const NO_STORE = ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'];

    /** @return Response|JsonResponse|null a response to return immediately, or null when all is well */
    private function gate(): Response|JsonResponse|null
    {
        if (!McpSchema::enabled()) {
            return response('Not found', 404);
        }
        if (!McpSchema::ready()) {
            return response()->json(['error' => 'temporarily_unavailable', 'error_description' => 'The Claude connector needs a database update (System settings -> Update database).'], 503);
        }

        return null;
    }

    private function oauthError(string $error, string $description, int $status = 400): JsonResponse
    {
        return response()->json(['error' => $error, 'error_description' => $description], $status, self::NO_STORE);
    }

    /* ------------------------------------------------------------------ discovery */

    public function protectedResource(Request $request)
    {
        if (!McpSchema::enabled()) {
            return response('Not found', 404);
        }
        $base = OAuth::base($request);

        return response()->json([
            'resource' => $base . '/mcp',
            'authorization_servers' => [$base],
            'bearer_methods_supported' => ['header'],
            'scopes_supported' => ['mcp'],
            'resource_name' => 'Archilance Portal',
        ], 200, ['Cache-Control' => 'public, max-age=300']);
    }

    public function authorizationServer(Request $request)
    {
        if (!McpSchema::enabled()) {
            return response('Not found', 404);
        }
        $base = OAuth::base($request);

        return response()->json([
            'issuer' => $base,
            'authorization_endpoint' => $base . '/oauth/authorize',
            'token_endpoint' => $base . '/oauth/token',
            'registration_endpoint' => $base . '/oauth/register',
            'revocation_endpoint' => $base . '/oauth/revoke',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => ['mcp'],
            'authorization_response_iss_parameter_supported' => true,
            // Present so OpenID-style discovery clients accept the same document.
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
        ], 200, ['Cache-Control' => 'public, max-age=300']);
    }

    /* ------------------------------------------------------------------ dynamic client registration */

    public function register(Request $request)
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $ip = OAuth::clientIp($request);
        $key = 'mcp-register:' . $ip;
        if (RateLimiter::tooManyAttempts($key, 30)) {
            return $this->oauthError('temporarily_unavailable', 'Too many registrations. Try again later.', 429);
        }
        RateLimiter::hit($key, 3600);

        $meta = $request->json()->all() ?: $request->all();
        $res = OAuth::registerClient(is_array($meta) ? $meta : [], $ip);
        if (isset($res['error'])) {
            return $this->oauthError($res['error'] === 'invalid_redirect_uri' ? 'invalid_redirect_uri' : 'invalid_client_metadata', $res['error_description']);
        }

        return response()->json([
            'client_id' => $res['client_id'],
            'client_id_issued_at' => time(),
            'client_name' => $res['client_name'],
            'redirect_uris' => $res['redirect_uris'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => 'mcp',
        ], 201, self::NO_STORE);
    }

    /* ------------------------------------------------------------------ sign-in page */

    private function page(string $html, int $status = 200): Response
    {
        return response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'",
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function backTo(string $redirect, array $params, string $base)
    {
        $params['iss'] = $base;
        $sep = str_contains($redirect, '?') ? '&' : '?';

        return redirect()->away($redirect . $sep . http_build_query($params));
    }

    /** GET /oauth/authorize */
    public function authorizeShow(Request $request)
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $clientId = (string) $request->query('client_id', '');
        $redirect = (string) $request->query('redirect_uri', '');
        $client = $clientId !== '' ? OAuth::client($clientId) : null;

        // Never redirect to an address we have not verified - show the problem here instead.
        if (!$client || !OAuth::clientHasRedirect($client, $redirect) || !OAuth::redirectAllowed($redirect)) {
            return $this->page(Pages::message('Cannot continue', 'This sign-in link is not valid. Start the connection again from Claude.'), 400);
        }
        $base = OAuth::base($request);
        $state = (string) $request->query('state', '');

        $fail = fn (string $code, string $why) => $this->backTo($redirect, array_filter(['error' => $code, 'error_description' => $why, 'state' => $state], fn ($v) => $v !== ''), $base);

        if ($request->query('response_type') !== 'code') {
            return $fail('unsupported_response_type', 'Only response_type=code is supported.');
        }
        $challenge = (string) $request->query('code_challenge', '');
        if ($request->query('code_challenge_method') !== 'S256' || !preg_match('/^[A-Za-z0-9_\-]{43,128}$/', $challenge)) {
            return $fail('invalid_request', 'PKCE with code_challenge_method=S256 is required.');
        }
        $resource = (string) $request->query('resource', '');
        // The audience is this server's /mcp URL. Compare the path only, so a host alias cannot break the sign-in.
        if ($resource !== '' && rtrim((string) parse_url($resource, PHP_URL_PATH), '/') !== rtrim((string) parse_url($base, PHP_URL_PATH), '/') . '/mcp') {
            return $fail('invalid_target', 'Unknown resource.');
        }

        $payload = [
            'c' => $client->client_id, 'r' => $redirect, 'ch' => $challenge, 's' => $state,
            'res' => $resource !== '' ? $resource : null, 'exp' => time() + 900,
        ];

        return $this->page(Pages::authorize([
            'req' => Crypt::encryptString(json_encode($payload)),
            'app' => (string) $client->client_name,
            'host' => (string) parse_url($redirect, PHP_URL_HOST),
        ]));
    }

    /** POST /oauth/authorize - password check, then back to Claude with a one-time code. */
    public function authorizeSubmit(Request $request)
    {
        if ($r = $this->gate()) {
            return $r;
        }
        try {
            $p = json_decode(Crypt::decryptString((string) $request->input('req')), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return $this->page(Pages::message('Link expired', 'This sign-in page is no longer valid. Start the connection again from Claude.'), 400);
        }
        if (!is_array($p) || ($p['exp'] ?? 0) < time() || !($client = OAuth::client((string) ($p['c'] ?? ''))) || !OAuth::clientHasRedirect($client, (string) ($p['r'] ?? ''))) {
            return $this->page(Pages::message('Link expired', 'This sign-in page is no longer valid. Start the connection again from Claude.'), 400);
        }
        $base = OAuth::base($request);
        $ip = OAuth::clientIp($request);
        $state = (string) ($p['s'] ?? '');

        if ($request->input('decision') === 'deny') {
            return $this->backTo($p['r'], array_filter(['error' => 'access_denied', 'error_description' => 'The user declined.', 'state' => $state], fn ($v) => $v !== ''), $base);
        }

        $ctx = ['req' => (string) $request->input('req'), 'app' => (string) $client->client_name, 'host' => (string) parse_url($p['r'], PHP_URL_HOST)];
        $login = trim((string) $request->input('login'));
        $password = (string) $request->input('password');
        $ipKey = 'mcp-login-ip:' . $ip;
        $acctKey = 'mcp-login-acct:' . mb_strtolower($login);

        if (RateLimiter::tooManyAttempts($ipKey, 20) || RateLimiter::tooManyAttempts($acctKey, 5)) {
            Audit::record(null, $client->client_id, 'oauth.login', 'denied', 'rate limited: ' . mb_substr($login, 0, 60), null, $ip);

            return $this->page(Pages::authorize($ctx, 'Too many attempts. Wait a few minutes and try again.', $login), 429);
        }

        $user = $login !== '' ? OAuth::findLoginUser($login) : null;
        $passwordOk = false;
        if ($user) {
            try {
                $passwordOk = Hash::check($password, (string) $user->password);
            } catch (\Throwable $e) {
                $passwordOk = false; // e.g. an old non-bcrypt hash: same as a wrong password, never a crash
            }
        }
        if (!$user || !$passwordOk) {
            RateLimiter::hit($ipKey, 600);
            RateLimiter::hit($acctKey, 600);
            Audit::record(null, $client->client_id, 'oauth.login', 'denied', 'wrong credentials: ' . mb_substr($login, 0, 60), null, $ip);

            return $this->page(Pages::authorize($ctx, 'Wrong email / username or password.', $login), 401);
        }
        if ($why = OAuth::whyNot($user)) {
            Audit::record((int) $user->id, $client->client_id, 'oauth.login', 'denied', $why, null, $ip);

            return $this->page(Pages::authorize($ctx, ucfirst($why) . '.', $login), 403);
        }

        RateLimiter::clear($acctKey);
        $code = OAuth::createCode($client, $user, $p['r'], $p['ch'], $p['res'] ?? null);
        Audit::record((int) $user->id, $client->client_id, 'oauth.login', 'ok', 'connected ' . $client->client_name, null, $ip);

        return $this->backTo($p['r'], array_filter(['code' => $code, 'state' => $state], fn ($v) => $v !== ''), $base);
    }

    /* ------------------------------------------------------------------ token + revoke */

    public function token(Request $request)
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $ip = OAuth::clientIp($request);
        $key = 'mcp-token:' . $ip;
        if (RateLimiter::tooManyAttempts($key, 60)) {
            return $this->oauthError('temporarily_unavailable', 'Too many requests.', 429);
        }
        RateLimiter::hit($key, 60);

        $grant = (string) $request->input('grant_type');
        $clientId = (string) $request->input('client_id');
        if ($clientId === '' || !OAuth::client($clientId)) {
            return $this->oauthError('invalid_client', 'Unknown client.', 401);
        }

        if ($grant === 'authorization_code') {
            $res = OAuth::exchangeCode((string) $request->input('code'), $clientId, (string) $request->input('redirect_uri'), (string) $request->input('code_verifier'));
        } elseif ($grant === 'refresh_token') {
            $res = OAuth::refresh((string) $request->input('refresh_token'), $clientId);
        } else {
            return $this->oauthError('unsupported_grant_type', 'Use authorization_code or refresh_token.');
        }

        if (isset($res['error'])) {
            return $this->oauthError($res['error'], $res['error_description'] ?? '', $res['error'] === 'access_denied' ? 403 : 400);
        }

        return response()->json($res, 200, self::NO_STORE);
    }

    public function revoke(Request $request)
    {
        if ($r = $this->gate()) {
            return $r;
        }
        $token = (string) $request->input('token');
        if ($token !== '') {
            OAuth::revoke($token);
        }

        return response()->json((object) [], 200, self::NO_STORE);
    }
}
