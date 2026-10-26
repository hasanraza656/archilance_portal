<?php

namespace App\Services\Mcp;

/** The two small HTML pages of the sign-in flow. No external files, scripts or fonts - nothing to block or break. */
final class Pages
{
    private static function shell(string $title, string $body): string
    {
        $t = e($title);

        return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>{$t}</title>
<style>
:root{color-scheme:light dark;--bg:#f4f5fb;--card:#fff;--ink:#14162b;--mute:#5c6080;--line:#dfe2ef;--brand:#4f46e5;--brand-ink:#fff;--bad:#c0262d;--badbg:#fdecec}
@media (prefers-color-scheme:dark){:root{--bg:#0e1020;--card:#171a31;--ink:#eef0ff;--mute:#a3a8cc;--line:#2a2e52;--brand:#7c74ff;--brand-ink:#0e1020;--bad:#ff8a8f;--badbg:#3a1c22}}
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;background:var(--bg);color:var(--ink);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.card{width:100%;max-width:440px;background:var(--card);border:1px solid var(--line);border-radius:18px;padding:28px 24px;box-shadow:0 10px 40px rgba(20,22,60,.10)}
h1{font-size:21px;margin:0 0 4px}p{margin:8px 0;color:var(--mute);font-size:15px}.who{background:var(--bg);border:1px solid var(--line);border-radius:12px;padding:12px 14px;margin:16px 0;color:var(--ink);font-size:15px}
.who b{display:block;font-size:16px}ul{margin:10px 0 16px;padding-left:20px;color:var(--mute);font-size:14px}li{margin:3px 0}
label{display:block;font-weight:600;font-size:14px;margin:14px 0 6px}input{width:100%;padding:12px 14px;font-size:16px;border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--ink)}input:focus{outline:2px solid var(--brand);outline-offset:1px}
.row{display:flex;gap:10px;margin-top:20px}button{flex:1;padding:13px 14px;font-size:16px;font-weight:600;border-radius:10px;border:1px solid var(--line);cursor:pointer;background:transparent;color:var(--ink)}button.go{background:var(--brand);color:var(--brand-ink);border-color:var(--brand)}
.err{background:var(--badbg);color:var(--bad);border-radius:10px;padding:10px 12px;font-size:14px;margin:12px 0}.small{font-size:12.5px;color:var(--mute);margin-top:16px}
</style></head><body><main class="card">{$body}</main></body></html>
HTML;
    }

    /** @param array{req:string,app:string,host:string} $ctx */
    public static function authorize(array $ctx, ?string $error = null, string $login = ''): string
    {
        $app = e($ctx['app']);
        $host = e($ctx['host']);
        $req = e($ctx['req']);
        $err = $error ? '<div class="err" role="alert">' . e($error) . '</div>' : '';
        $login = e($login);

        $body = <<<HTML
<h1>Connect Claude to Archilance Portal</h1>
<p>Sign in with your portal administrator account to let this app work on your behalf.</p>
<div class="who"><b>{$app}</b>will be sent back to <span>{$host}</span> after you approve.</div>
<ul>
<li>It can read portal data and make changes as <b>you</b> - projects, tasks, leave, payroll, chat.</li>
<li>Everything it does is recorded in an activity log.</li>
<li>Nothing can be deleted this way. You can disconnect it at any time.</li>
</ul>
{$err}
<form method="post" autocomplete="on">
<input type="hidden" name="req" value="{$req}">
<label for="login">Email, username or phone</label>
<input id="login" name="login" value="{$login}" autocomplete="username" autocapitalize="none" required autofocus>
<label for="password">Password</label>
<input id="password" name="password" type="password" autocomplete="current-password" required>
<div class="row"><button type="submit" name="decision" value="deny" formnovalidate>Cancel</button><button class="go" type="submit" name="decision" value="allow">Sign in &amp; allow</button></div>
</form>
<p class="small">Only continue if you started this connection yourself from Claude.</p>
HTML;

        return self::shell('Connect Claude - Archilance Portal', $body);
    }

    public static function message(string $title, string $text): string
    {
        return self::shell($title, '<h1>' . e($title) . '</h1><p>' . e($text) . '</p>');
    }
}
