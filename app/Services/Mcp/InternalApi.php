<?php

namespace App\Services\Mcp;

use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use Illuminate\Validation\ValidationException;

/**
 * Calls the portal's OWN admin API from inside the same request, as the signed-in admin.
 *
 * Why: every rule, validation, notification, e-mail and side effect the web portal has already lives behind those
 * routes. Going through them means Claude does exactly what the admin would do by clicking in the portal - same
 * checks, same notifications - instead of a second, drifting copy of the logic. The route's own middleware
 * (auth + role:2) still runs, so a non-admin could never get through even if a bug let one reach this class.
 */
class InternalApi
{
    public function __construct(private User $user)
    {
    }

    /** @return array{status:int, ok:bool, data:mixed} */
    public function call(string $method, string $path, array $params = []): array
    {
        $method = strtoupper($method);
        $path = '/api/' . ltrim(preg_replace('#^/?api/#', '', $path), '/');

        $server = ['HTTP_ACCEPT' => 'application/json'];
        if ($method === 'GET') {
            $sub = Request::create($path, 'GET', $params, [], [], $server);
        } else {
            $server['CONTENT_TYPE'] = 'application/json';
            $sub = Request::create($path, $method, [], [], [], $server, json_encode($params, JSON_UNESCAPED_UNICODE));
        }

        $app = app();
        $previous = $app->bound('request') ? $app->make('request') : null;

        // Same swap the HTTP kernel does for the real request.
        $app->instance('request', $sub);
        Facade::clearResolvedInstance('request');

        try {
            Auth::shouldUse('sanctum');
            Auth::guard('sanctum')->setUser($this->user);
            $sub->setUserResolver(fn () => $this->user);

            $response = $app->make('router')->dispatch($sub);
        } catch (ValidationException $e) {
            $response = $app->make(ExceptionHandler::class)->render($sub, $e);
        } catch (\Throwable $e) {
            try {
                $app->make(ExceptionHandler::class)->report($e);
            } catch (\Throwable $ignored) {
                // reporting must never hide the real answer
            }
            $response = $app->make(ExceptionHandler::class)->render($sub, $e);
        } finally {
            // Leave no trace of the borrowed identity behind for whatever runs next in this request.
            try {
                Auth::forgetGuards();
                Auth::shouldUse((string) config('auth.defaults.guard', 'web'));
            } catch (\Throwable $ignored) {
                // never let clean-up hide the answer
            }
            if ($previous) {
                $app->instance('request', $previous);
                Facade::clearResolvedInstance('request');
            }
        }

        $status = $response->getStatusCode();
        $body = method_exists($response, 'getContent') ? (string) $response->getContent() : '';
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $data = $body === '' ? null : ['raw' => mb_substr(strip_tags($body), 0, 500)];
        }

        return ['status' => $status, 'ok' => $status < 400, 'data' => $data];
    }

    public function get(string $path, array $query = []): array
    {
        return $this->call('GET', $path, $query);
    }

    public function post(string $path, array $body = []): array
    {
        return $this->call('POST', $path, $body);
    }

    public function put(string $path, array $body = []): array
    {
        return $this->call('PUT', $path, $body);
    }

    /** Same as call(), but turns a failure into a ToolError carrying the portal's own message. */
    public function must(array $result): mixed
    {
        if ($result['ok']) {
            return $result['data'];
        }

        throw new ToolError(self::explain($result));
    }

    public static function explain(array $result): string
    {
        $data = $result['data'];
        $msg = is_array($data) ? ($data['message'] ?? $data['error'] ?? null) : null;
        $detail = '';
        if (is_array($data) && !empty($data['errors']) && is_array($data['errors'])) {
            $parts = [];
            foreach ($data['errors'] as $field => $errs) {
                $parts[] = $field . ': ' . (is_array($errs) ? implode(' ', $errs) : $errs);
            }
            $detail = ' (' . implode('; ', $parts) . ')';
        }
        if (is_array($data) && !empty($data['policy_violation'])) {
            $detail .= ' [policy: ' . $data['policy_violation'] . (!empty($data['can_override']) ? '; an admin can override' : '') . ']';
        }

        $text = $msg ?: match (true) {
            $result['status'] === 403 => 'The portal refused this (not allowed).',
            $result['status'] === 404 => 'Not found.',
            $result['status'] === 422 => 'The portal rejected the input.',
            default => 'The portal returned an error.',
        };

        return "Portal error {$result['status']}: {$text}{$detail}";
    }
}
