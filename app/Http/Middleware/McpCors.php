<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS for the Claude connector URLs only (/mcp, /oauth/*, /.well-known/*): browser-based MCP tools need it.
 * Safe with "*": these endpoints use bearer tokens in a header, never cookies.
 */
class McpCors
{
    private const HEADERS = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, DELETE, OPTIONS',
        'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept, Mcp-Protocol-Version, Mcp-Session-Id, Last-Event-ID',
        'Access-Control-Expose-Headers' => 'WWW-Authenticate, Mcp-Session-Id',
        'Access-Control-Max-Age' => '600',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $request->isMethod('OPTIONS') ? response('', 204) : $next($request);
        foreach (self::HEADERS as $k => $v) {
            $response->headers->set($k, $v);
        }

        return $response;
    }
}
