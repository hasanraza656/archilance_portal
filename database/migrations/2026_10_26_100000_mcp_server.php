<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MCP server (Claude connector for admins): OAuth clients, one-time authorization codes, access/refresh tokens
 * and an audit log of everything Claude does on the portal.
 *
 * Tokens and codes are stored only as SHA-256 hashes, so a database leak cannot be replayed against the portal.
 * Additive and guarded: safe to run twice, touches no existing table.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('mcp_clients')) {
            Schema::create('mcp_clients', function (Blueprint $t) {
                $t->id();
                $t->string('client_id', 64)->unique();
                $t->string('client_name', 120)->nullable();
                $t->text('redirect_uris');                       // JSON array of exact redirect URIs
                $t->string('registered_ip', 45)->nullable();
                $t->timestamp('last_used_at')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('mcp_auth_codes')) {
            Schema::create('mcp_auth_codes', function (Blueprint $t) {
                $t->id();
                $t->char('code_hash', 64)->unique();
                $t->string('client_id', 64)->index();
                $t->unsignedBigInteger('user_id');
                $t->text('redirect_uri');
                $t->string('code_challenge', 128);               // PKCE (S256)
                $t->string('resource', 255)->nullable();
                $t->dateTime('expires_at');
                $t->dateTime('used_at')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }

        if (!Schema::hasTable('mcp_tokens')) {
            Schema::create('mcp_tokens', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('client_id', 64)->index();
                $t->char('access_hash', 64)->unique();
                $t->char('refresh_hash', 64)->nullable()->unique();
                $t->dateTime('access_expires_at');
                $t->dateTime('refresh_expires_at')->nullable();
                $t->dateTime('last_used_at')->nullable();
                $t->string('last_ip', 45)->nullable();
                $t->dateTime('revoked_at')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('mcp_audit_logs')) {
            Schema::create('mcp_audit_logs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->nullable()->index();
                $t->string('client_id', 64)->nullable();
                $t->string('tool', 80)->index();
                $t->string('status', 12);                        // ok | error | denied
                $t->text('summary')->nullable();                 // arguments (trimmed) and the outcome
                $t->unsignedInteger('duration_ms')->nullable();
                $t->string('ip', 45)->nullable();
                $t->timestamp('created_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: additive only.
    }
};
