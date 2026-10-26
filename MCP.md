# Archilance Portal — Claude connector (MCP) for admins

Lets a portal **administrator** (user_role 2) connect their Claude (claude.ai, Claude Desktop, Claude Code) to the portal and,
in plain conversation, read and act on the same things they can in the web portal: unread chat and replies, projects, tasks and
sub-tasks, assignees, work diaries and hours, reports, leave approvals, payroll.

* Server URL to give people: **`https://<your backend host>/mcp`** — e.g. `https://backend.archilance.org/mcp`
* Pure Laravel, **no new package** (nothing to install on the server, no `composer`).
* Everything is **off** until `MCP_ENABLED=true`; before that every connector URL answers 404 and nothing else in the portal changes.

## How it works

```
Claude ──(OAuth 2.1 sign-in with the admin's portal login, PKCE)──▶ /oauth/*          (tokens, stored as hashes)
Claude ──(MCP over HTTPS, Bearer token)───────────────────────────▶ /mcp  → 48 tools
                                                                          │ each tool calls the portal's OWN admin API
                                                                          ▼ in-process, as that admin (auth + role:2 still run)
                                                                     existing controllers → same validation, notifications, e-mails
```

Because tools go through the existing `/api/admin/*` routes, Claude does exactly what the admin would do by clicking in the
portal. There is no second copy of business logic to drift.

## Go-live checklist (no terminal needed)

1. **Upload** (all new, plus two edited files — see "Files" below). No `vendor/` changes.
2. **Database**: v2 portal → *System settings → Update database* (registers as `mcp_server`), or run `database/sql/2026_10_26_mcp_server.sql` in phpMyAdmin.
   The update list runs oldest-first and stops at the first failure — make sure the earlier chat migrations
   (`2026_10_24_100000_chat_v2.php`, `2026_10_25_100000_chat_video.php`) are on the server too.
3. **`.env`** on the server:
   ```
   MCP_ENABLED=true
   MCP_ALLOWED_ADMIN_EMAILS=first-admin@example.com,second-admin@example.com   # recommended
   ```
   Optional: `MCP_READ_ONLY=true` (Claude can only read), `MCP_PUBLIC_URL=https://backend.archilance.org` (only if the
   discovery documents show a wrong address), `MCP_ACCESS_TTL_MINUTES=60`, `MCP_REFRESH_TTL_DAYS=30`, `MCP_RATE_LIMIT=120`,
   `MCP_ALLOWED_REDIRECT_HOSTS=extra.host.com`.
4. Open `https://<backend host>/clear-cache` once (the server caches config; this makes it read the new `.env` values).
5. **Check**: `https://<backend host>/.well-known/oauth-authorization-server` must show JSON. (404 = `MCP_ENABLED` not picked up.)
6. **Connect** (each admin, once):
   * claude.ai / Claude Desktop → Settings → Connectors → *Add custom connector* → URL `https://<backend host>/mcp` → Connect →
     sign in with the **portal admin email + password** on the page that opens → *Sign in & allow*.
   * Claude Code: `claude mcp add --transport http archilance https://<backend host>/mcp`, then `/mcp` → authenticate.

## What Claude can do

| Area | Tools |
|---|---|
| Start here | `get_portal_overview` |
| Chat (the admin's own inbox) | `chat_inbox` (unread), `chat_read_conversation`, `chat_read_thread`, `chat_send_message` (replies, group mentions), `chat_mark_read`, `chat_search`, `my_notifications` — uses chat v2 when installed, the original chat otherwise |
| Projects | `list_projects`, `get_project`, `create_project`, `update_project`, `set_project_status`, `set_project_team` |
| Tasks & sub-tasks | `list_tasks` (any level, many filters), `get_task`, `create_task`, `update_task`, `set_task_assignees`, `list_task_comments`, `add_task_comment`, `project_chat_messages`, `project_chat_send` |
| People | `find_people`, `list_users`, `get_user`, `who_is_working_now` |
| Time & reports | `work_diary`, `summary_report`, `employee_average_hours`, `add_manual_time` (confirm) |
| Leave | `list_leave_requests`, `get_leave_request`, `decide_leave_request` (approve/reject), `record_leave_for_employee` |
| Payroll | `payroll_runs`, `payroll_run_detail`, `payroll_report`, `salary_overview`, `payroll_generate`, `payroll_run_action` (confirm), `payslip_action` (confirm) |
| Everything else | `list_api_endpoints`, `api_read` (any admin GET), `api_write` (POST/PUT/PATCH, confirm) |
| The connector itself | `list_claude_connections`, `revoke_claude_connection`, `claude_activity_log` |

## Safety model

* **Admins only.** The role (and the optional e-mail allow-list) is re-checked on **every** call: demote or delete an admin and Claude loses access immediately.
* **Own identity.** Everything is done *as the signed-in admin* — chat messages are sent as them, comments are authored by them. Two admins = two separate identities and inboxes.
* **No deletes, anywhere.** (Project / task deletes are permanent in this portal.) **No account, password or role changes**, no system settings — those stay in the web portal. Blocked in the generic `api_write` too.
* **Confirmation gate.** Tools that record payments, add time, change payroll, or POST via `api_write` answer *"NOT DONE – confirmation required"* with a plain-language preview until called again with `confirm=true`. Marking a project *Completed* (which completes all its tasks) is gated the same way.
* **Prompt-injection aware.** The server tells Claude that chat/comment/leave text is *data*, never instructions.
* **Audit log** (`mcp_audit_logs`): every call — who, tool, arguments (secrets masked), result — viewable via `claude_activity_log`.
* **Tokens**: only SHA-256 hashes stored; access tokens last 60 min, refresh tokens 30 days and rotate on use; a replayed sign-in code revokes what it produced. MCP tokens are rejected by the normal `/api/*` routes.
* **Login protection**: 5 wrong passwords / 10 min per account, 20 per IP; sign-in redirects only to claude.ai / claude.com / localhost (+ `MCP_ALLOWED_REDIRECT_HOSTS`).
* **Kill switches**: `MCP_ENABLED=false` (all URLs 404), `MCP_READ_ONLY=true`, or `revoke_claude_connection` (one or all).

## Files

New: `config/mcp.php`, `routes/mcp.php`, `app/Support/McpSchema.php`, `app/Http/Middleware/McpCors.php`,
`app/Http/Controllers/Mcp/{McpController,OAuthController}.php`, `app/Services/Mcp/*.php` (incl. `Tools/`),
`database/migrations/2026_10_26_100000_mcp_server.php`, `database/sql/2026_10_26_mcp_server.sql`.
Edited (additive only): `app/Support/DatabaseUpdates.php` (one registry entry), `routes/web.php` (4 lines appended at the end).

## Notes / limits

* Chat is **text only** (no attachments through Claude). Hours are the portal's own numbers (idle removed).
* Timers left running since before yesterday are treated as forgotten in "who is working now".
* The portal's own quirks apply (e.g. "Completed" on a project completes its tasks; `PUT` endpoints that replace all fields — `api_write` warns).
* `update_project` / `update_task` resend the current values of untouched fields, so the portal doesn't send spurious "due date / status changed" notifications.

## How it was tested

~350 automated checks against the real controllers + real migrations (SQLite): OAuth flow, PKCE, rotation, rate limits, role demotion, each
tool (including side effects such as notifications), confirmation gates, read-only mode, blocked paths, audit log, "database update not applied yet"
states, and the existing API's behaviour unchanged. Plus the **official MCP TypeScript SDK** (v1.12 and v1.32) against the running server:
discovery → dynamic registration → PKCE login → tools → refresh.
