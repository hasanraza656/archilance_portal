<?php

/*
|--------------------------------------------------------------------------
| Claude connector (MCP) routes
|--------------------------------------------------------------------------
| Loaded from routes/web.php. Additive: these URLs did not exist before, and every one of them answers 404 until
| MCP_ENABLED=true is set in .env. They run WITHOUT the web middleware group (no session, no cookies, no CSRF):
| the connector is a token API, and the sign-in form carries its own tamper-proof, expiring request token.
|
|   POST /mcp                                     the MCP endpoint
|   GET  /.well-known/oauth-protected-resource    discovery (also with a path suffix)
|   GET  /.well-known/oauth-authorization-server  discovery (also with a path suffix)
|   POST /oauth/register                          dynamic client registration
|   GET|POST /oauth/authorize                     sign-in page
|   POST /oauth/token  /oauth/revoke
*/

use App\Http\Controllers\Mcp\McpController;
use App\Http\Controllers\Mcp\OAuthController;
use App\Http\Middleware\McpCors;
use Illuminate\Support\Facades\Route;

Route::withoutMiddleware(app('router')->getMiddlewareGroups()['web'] ?? [])
    ->middleware(McpCors::class)
    ->group(function () {
        Route::match(['get', 'post', 'delete', 'options'], '/mcp', [McpController::class, 'handle']);

        Route::match(['get', 'options'], '/.well-known/oauth-protected-resource/{path?}', [OAuthController::class, 'protectedResource'])->where('path', '.*');
        Route::match(['get', 'options'], '/.well-known/oauth-authorization-server/{path?}', [OAuthController::class, 'authorizationServer'])->where('path', '.*');
        Route::match(['get', 'options'], '/.well-known/openid-configuration/{path?}', [OAuthController::class, 'authorizationServer'])->where('path', '.*');

        Route::match(['post', 'options'], '/oauth/register', [OAuthController::class, 'register']);
        Route::get('/oauth/authorize', [OAuthController::class, 'authorizeShow']);
        Route::post('/oauth/authorize', [OAuthController::class, 'authorizeSubmit']);
        Route::match(['post', 'options'], '/oauth/token', [OAuthController::class, 'token']);
        Route::match(['post', 'options'], '/oauth/revoke', [OAuthController::class, 'revoke']);
    });
