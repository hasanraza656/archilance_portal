<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Services\Mcp\McpServer;
use App\Services\Mcp\OAuth;
use App\Support\McpSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** POST /mcp - the Model Context Protocol endpoint (Streamable HTTP, JSON responses, stateless). */
class McpController extends Controller
{
    private const MAX_BODY = 1_000_000;

    private function unauthorized(Request $request, bool $invalid)
    {
        $meta = OAuth::base($request) . '/.well-known/oauth-protected-resource';
        $header = 'Bearer realm="Archilance Portal", resource_metadata="' . $meta . '"' . ($invalid ? ', error="invalid_token"' : '');

        return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'Authentication required']], 401, ['WWW-Authenticate' => $header]);
    }

    public function handle(Request $request)
    {
        if (!McpSchema::enabled()) {
            return response('Not found', 404);
        }
        if (!McpSchema::ready()) {
            return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => 'The Claude connector needs a database update (System settings -> Update database).']], 503);
        }
        if ($request->method() !== 'POST') {
            return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => 'This server answers POST requests only.']], 405, ['Allow' => 'POST, OPTIONS']);
        }

        $bearer = (string) $request->bearerToken();
        $auth = OAuth::authenticate($bearer, $request);
        if (!$auth) {
            return $this->unauthorized($request, $bearer !== '');
        }

        $limit = (int) (McpSchema::settings()['rate_limit_per_minute'] ?? 120);
        $key = 'mcp-call:' . $auth['token_id'];
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => 'Too many requests - slow down.']], 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 60);

        $raw = $request->getContent();
        if (strlen($raw) > self::MAX_BODY) {
            return response()->json(McpServer::error(null, -32600, 'Request too large'), 413);
        }
        $body = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
            return response()->json(McpServer::error(null, -32700, 'Parse error'), 400);
        }

        $server = new McpServer($auth['user'], $auth['client_id'], OAuth::clientIp($request));

        if (array_is_list($body) && $body !== []) {
            $out = [];
            foreach (array_slice($body, 0, 20) as $msg) {
                $res = $server->handle($msg);
                if ($res !== null) {
                    $out[] = $res;
                }
            }

            return $out ? response()->json($out) : response('', 202);
        }

        $res = $server->handle($body);

        return $res === null ? response('', 202) : response()->json($res);
    }
}
