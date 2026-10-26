<?php

namespace App\Services\Mcp\Tools;

use App\Models\LeaveRequest;
use App\Models\ProjectTask;
use App\Models\WorkSession;
use App\Services\Mcp\Present;
use App\Services\Mcp\S;
use App\Services\Mcp\Tool;
use App\Services\Mcp\ToolContext;
use App\Services\Mcp\ToolError;
use App\Support\ChatSchema;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The overview, the "everything else the admin portal can do" doorway (read any admin endpoint, write with confirmation),
 * and the controls for the Claude connection itself (who is connected, what Claude did).
 *
 * The doorway is deliberately fenced: only /admin/* and the contracts endpoints; no deletes (project / task deletes are
 * permanent in this portal); and no account / password / role changes - those stay in the web portal.
 */
final class AdminTools
{
    /** Paths the generic tools may touch. */
    private const ROOTS = ['admin', 'contracts', 'contract-templates', 'contract-variables'];

    /** Writes that are never allowed through the generic door. */
    private const WRITE_BLOCKED = [
        '#^admin/(employee|customer|supervisor|admin)-user#' => 'creating, editing or deleting accounts (including passwords and roles) is only possible in the web portal',
        '#^admin/system/#' => 'system settings and database updates are only possible in the web portal',
        '#^admin/(delete-screenshot|delete-idle-time)#' => 'deleting screenshots / idle time is only possible in the web portal',
        '#^admin/trashed-users#' => 'restoring deleted accounts is only possible in the web portal',
    ];

    /** @return Tool[] */
    public static function all(): array
    {
        return [self::overview(), self::listEndpoints(), self::apiRead(), self::apiWrite(), self::connections(), self::revoke(), self::activity()];
    }

    /* ------------------------------------------------------------------ path rules */

    /** @throws ToolError */
    public static function normalizePath(string $path): string
    {
        $p = trim($path);
        $p = preg_replace('#^https?://[^/]+#i', '', $p);
        $p = ltrim($p, '/');
        $p = preg_replace('#^api/#', '', $p);
        $p = rtrim($p, '/');
        if ($p === '' || !preg_match('#^[A-Za-z0-9_\-/]+$#', $p) || str_contains($p, '..') || str_contains($p, '//')) {
            throw new ToolError("Invalid path '{$path}'. Use something like admin/leave-request - no query string (pass query separately).");
        }
        $root = explode('/', $p)[0];
        if (!in_array($root, self::ROOTS, true)) {
            throw new ToolError("Path '{$p}' is outside what this connector may use (only admin/* and contracts endpoints). Use list_api_endpoints to see what exists.");
        }

        return $p;
    }

    /** Returns null when allowed, otherwise the reason. */
    public static function writeBlocked(string $path): ?string
    {
        foreach (self::WRITE_BLOCKED as $re => $why) {
            if (preg_match($re, $path)) {
                return "Refused: {$why}.";
            }
        }

        return null;
    }

    private static function assertRoute(string $method, string $path): void
    {
        $req = Request::create('/api/' . $path, $method);
        try {
            app('router')->getRoutes()->match($req);
        } catch (\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException $e) {
            throw new ToolError("No {$method} endpoint at '{$path}' (other methods exist there). Use list_api_endpoints.");
        } catch (\Throwable $e) {
            throw new ToolError("No endpoint at '{$path}'. Use list_api_endpoints to see what exists.");
        }
    }

    /* ------------------------------------------------------------------ tools */

    private static function overview(): Tool
    {
        return new Tool(
            'get_portal_overview',
            'Portal overview (start here)',
            'One-call snapshot for the admin: counts of projects / tasks / people, overdue tasks, leave requests waiting for a decision, who is working right now, unread chat messages and unread notifications. Good opening move for "what needs my attention?".',
            [],
            [],
            Tool::READ,
            function (array $a, ToolContext $c) {
                $dash = $c->api->must($c->api->get('admin/dashboard'));
                $today = $c->today();

                $unread = null;
                $inbox = ChatSchema::ready() ? $c->api->get('chat/v2/inbox') : ['ok' => false];
                if ($inbox['ok']) {
                    $unread = ['messages' => (int) ($inbox['data']['unread_total'] ?? 0), 'conversations' => (int) ($inbox['data']['unread_conversations'] ?? 0)];
                } else {
                    $leg = $c->api->get('chat/unread-count');
                    $unread = $leg['ok'] ? ['messages' => (int) ($leg['data']['unread_count'] ?? 0)] : null;
                }

                $notif = null;
                try {
                    $notif = \App\Models\Notification::where('user_id', $c->user->id)->where('is_read', 0)->count();
                } catch (\Throwable $e) {
                    // optional
                }

                return Present::clean([
                    'now' => Carbon::now()->format('Y-m-d H:i') . ' (' . config('app.timezone') . ')',
                    'signed_in_as' => ['id' => (int) $c->user->id, 'name' => $c->user->name],
                    'projects' => ['total' => $dash['total_projects'] ?? null, 'in_progress' => $dash['total_in_progress_projects'] ?? null, 'completed' => $dash['total_completed_projects'] ?? null],
                    'top_level_tasks' => $dash['total_tasks'] ?? null,
                    'people' => ['accounts' => $dash['total_users'] ?? null, 'employees' => $dash['total_employees'] ?? null, 'customers' => $dash['total_customers'] ?? null],
                    'overdue_tasks' => ProjectTask::where('task_status', '!=', 'Completed')->whereNotNull('due_date')->whereDate('due_date', '<', $today)->count(),
                    'leave_requests_pending' => LeaveRequest::where('status', 'Pending')->count(),
                    'timers_running_now' => WorkSession::whereNull('end_time')->whereNull('end_date')->whereDate('start_date', '>=', Carbon::now()->subDay()->toDateString())->distinct()->count('user_id'),
                    'unread_chat' => $unread,
                    'unread_notifications' => $notif,
                ]);
            }
        );
    }

    private static function listEndpoints(): Tool
    {
        return new Tool(
            'list_api_endpoints',
            'List other admin endpoints',
            'Lists the portal\'s admin API endpoints that api_read / api_write can use, so you can do things there is no dedicated tool for (customer teams, notes, working hours, evaluations, org chart, subscriptions, contracts, salary settings, ...). Shows method, path and the controller action. Path placeholders like {id} must be replaced with real ids.',
            ['contains' => S::str('Only endpoints whose path contains this text, e.g. "working-hours" or "note".', 60), 'limit' => S::limit(120, 300)],
            [],
            Tool::READ,
            function (array $a, ToolContext $c) {
                $rows = [];
                foreach (app('router')->getRoutes() as $route) {
                    $uri = $route->uri();
                    if (!str_starts_with($uri, 'api/')) {
                        continue;
                    }
                    $path = substr($uri, 4);
                    if (!in_array(explode('/', $path)[0], self::ROOTS, true)) {
                        continue;
                    }
                    if (!empty($a['contains']) && stripos($path, $a['contains']) === false) {
                        continue;
                    }
                    foreach ($route->methods() as $m) {
                        if (in_array($m, ['HEAD', 'DELETE'], true)) {
                            continue;
                        }
                        $note = null;
                        if ($m !== 'GET' && self::writeBlocked($path)) {
                            $note = 'blocked (web portal only)';
                        }
                        $action = $route->getActionName();
                        $rows[] = Present::clean(['method' => $m === 'PATCH' ? 'PUT/PATCH' : $m, 'path' => $path, 'action' => str_contains($action, '@') ? class_basename(explode('@', $action)[0]) . '@' . explode('@', $action)[1] : $action, 'note' => $note]);
                    }
                }
                // PUT and PATCH are the same endpoint: show once
                $seen = [];
                $rows = array_values(array_filter($rows, function ($r) use (&$seen) {
                    $k = ($r['method'] === 'PUT/PATCH' ? 'PUT' : $r['method']) . ' ' . $r['path'];
                    if (isset($seen[$k])) {
                        return false;
                    }
                    $seen[$k] = true;

                    return true;
                }));
                usort($rows, fn ($x, $y) => strcmp($x['path'] . $x['method'], $y['path'] . $y['method']));

                return ['note' => 'Deletes are not available through this connector. Account / password / role changes and system settings are web-portal only.'] + Present::page($rows, $c->limit($a, 120, 300));
            }
        );
    }

    private static function apiRead(): Tool
    {
        return new Tool(
            'api_read',
            'Read any admin endpoint',
            'GET any admin endpoint (see list_api_endpoints), as the signed-in admin, with optional query parameters. Long results are shortened - use filters / page parameters the endpoint offers.',
            [
                'path' => S::str('Endpoint path, e.g. admin/working-hours or admin/customer-team. No query string here.', 200),
                'query' => S::obj('Query parameters as an object, e.g. {"page": 2, "search": "ali"}.'),
            ],
            ['path'],
            Tool::READ,
            function (array $a, ToolContext $c) {
                $path = self::normalizePath($a['path']);
                self::assertRoute('GET', $path);
                $r = $c->api->call('GET', $path, $a['query'] ?? []);
                if (!$r['ok']) {
                    throw new ToolError(\App\Services\Mcp\InternalApi::explain($r));
                }

                return Present::durations(Present::clean($r['data']));
            }
        );
    }

    private static function apiWrite(): Tool
    {
        return new Tool(
            'api_write',
            'Change something via any admin endpoint',
            'POST / PUT / PATCH to an admin endpoint that has no dedicated tool. CAUTION: many portal "update" endpoints REPLACE every field - read the record first (api_read) and send all of its current fields plus your change, or you may blank data. Needs confirm=true. Deletes, account/password/role changes and system settings are refused.',
            [
                'method' => S::enum('HTTP method.', ['POST', 'PUT', 'PATCH']),
                'path' => S::str('Endpoint path, e.g. admin/working-hours/12. No query string.', 200),
                'body' => S::obj('JSON body to send.'),
            ],
            ['method', 'path'],
            Tool::DANGER,
            function (array $a, ToolContext $c) {
                $path = self::normalizePath($a['path']);
                if ($why = self::writeBlocked($path)) {
                    throw new ToolError($why);
                }
                self::assertRoute($a['method'], $path);
                $r = $c->api->call($a['method'], $path, $a['body'] ?? []);
                if (!$r['ok']) {
                    throw new ToolError(\App\Services\Mcp\InternalApi::explain($r));
                }

                return ['done' => true, 'status' => $r['status'], 'response' => Present::clean($r['data'])];
            },
            function (array $a, ToolContext $c) {
                $path = self::normalizePath($a['path']);
                if ($why = self::writeBlocked($path)) {
                    throw new ToolError($why);
                }
                self::assertRoute($a['method'], $path);

                return "{$a['method']} {$path} with body " . mb_substr((string) json_encode($a['body'] ?? new \stdClass(), JSON_UNESCAPED_UNICODE), 0, 700) . '. The portal runs its normal validation and notifications.';
            }
        );
    }

    /* ------------------------------------------------------------------ the Claude connection itself */

    private static function connections(): Tool
    {
        return new Tool(
            'list_claude_connections',
            'Who is connected',
            'Lists the active Claude connections to this portal (which admin, which app, when connected, last used, from which IP). Use it to check nobody unexpected is connected.',
            [],
            [],
            Tool::READ,
            function (array $a, ToolContext $c) {
                $now = Carbon::now()->toDateTimeString();
                $rows = DB::table('mcp_tokens as t')
                    ->leftJoin('mcp_clients as cl', 'cl.client_id', '=', 't.client_id')
                    ->leftJoin('users as u', 'u.id', '=', 't.user_id')
                    ->whereNull('t.revoked_at')
                    ->where(fn ($q) => $q->where('t.refresh_expires_at', '>', $now)->orWhere('t.access_expires_at', '>', $now))
                    ->orderByDesc('t.last_used_at')
                    ->limit(100)
                    ->get(['t.id', 't.user_id', 'u.name as admin', 'cl.client_name as app', 't.created_at', 't.last_used_at', 't.last_ip', 't.client_id'])
                    ->map(fn ($r) => Present::clean([
                        'connection_id' => (int) $r->id,
                        'admin' => ['id' => (int) $r->user_id, 'name' => $r->admin],
                        'app' => $r->app ?: 'unnamed app',
                        'connected_at' => $r->created_at,
                        'last_used_at' => $r->last_used_at,
                        'last_ip' => $r->last_ip,
                        'this_one' => null,
                    ]))->all();

                return Present::page($rows, 100);
            }
        );
    }

    private static function revoke(): Tool
    {
        return new Tool(
            'revoke_claude_connection',
            'Disconnect a Claude connection',
            'Immediately disconnects one Claude connection (use list_claude_connections for ids). That app must sign in again to reconnect. Pass connection_id, or everything=true to disconnect ALL connections (including this one).',
            [
                'connection_id' => S::int('Connection id.', 1),
                'everything' => S::bool('Disconnect every connection of every admin.'),
            ],
            [],
            Tool::WRITE,
            function (array $a, ToolContext $c) {
                if (empty($a['connection_id']) && empty($a['everything'])) {
                    throw new ToolError('Pass connection_id or everything=true.');
                }
                $q = DB::table('mcp_tokens')->whereNull('revoked_at');
                if (empty($a['everything'])) {
                    $q->where('id', $a['connection_id']);
                }
                $n = $q->update(['revoked_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

                return ['disconnected' => $n];
            }
        );
    }

    private static function activity(): Tool
    {
        return new Tool(
            'claude_activity_log',
            'What Claude did in the portal',
            'The audit trail of this connector: every tool call (who, which tool, what arguments, success or failure), newest first. Use it to answer "what has Claude changed?".',
            [
                'tool' => S::str('Only this tool name.', 80),
                'only_changes' => S::bool('Hide read-only look-ups.'),
                'limit' => S::limit(30, 200),
            ],
            [],
            Tool::READ,
            function (array $a, ToolContext $c) {
                $q = DB::table('mcp_audit_logs as l')->leftJoin('users as u', 'u.id', '=', 'l.user_id');
                if (!empty($a['tool'])) {
                    $q->where('l.tool', $a['tool']);
                }
                if (!empty($a['only_changes'])) {
                    $q->whereIn('l.tool', array_map(fn (Tool $t) => $t->name, array_filter(\App\Services\Mcp\Catalog::all(), fn (Tool $t) => $t->kind !== Tool::READ)));
                }
                $limit = $c->limit($a, 30, 200);
                $rows = $q->orderByDesc('l.id')->limit($limit)->get(['l.id', 'l.created_at', 'u.name as admin', 'l.tool', 'l.status', 'l.summary', 'l.duration_ms'])
                    ->map(fn ($r) => Present::clean(['at' => $r->created_at, 'admin' => $r->admin, 'tool' => $r->tool, 'status' => $r->status, 'details' => $r->summary, 'ms' => $r->duration_ms]))->all();

                return Present::page($rows, $limit);
            }
        );
    }
}
