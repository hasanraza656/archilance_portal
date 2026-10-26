-- MCP server (Claude connector for admins).
-- Easiest way: v2 portal -> System settings -> "Update database". Use this file only if you prefer phpMyAdmin.
-- Safe to run more than once (CREATE TABLE IF NOT EXISTS). Touches no existing table.

CREATE TABLE IF NOT EXISTS `mcp_clients` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` varchar(64) NOT NULL,
  `client_name` varchar(120) DEFAULT NULL,
  `redirect_uris` text NOT NULL,
  `registered_ip` varchar(45) DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mcp_clients_client_id_unique` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mcp_auth_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code_hash` char(64) NOT NULL,
  `client_id` varchar(64) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `redirect_uri` text NOT NULL,
  `code_challenge` varchar(128) NOT NULL,
  `resource` varchar(255) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mcp_auth_codes_code_hash_unique` (`code_hash`),
  KEY `mcp_auth_codes_client_id_index` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mcp_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `client_id` varchar(64) NOT NULL,
  `access_hash` char(64) NOT NULL,
  `refresh_hash` char(64) DEFAULT NULL,
  `access_expires_at` datetime NOT NULL,
  `refresh_expires_at` datetime DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `last_ip` varchar(45) DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mcp_tokens_access_hash_unique` (`access_hash`),
  UNIQUE KEY `mcp_tokens_refresh_hash_unique` (`refresh_hash`),
  KEY `mcp_tokens_user_id_index` (`user_id`),
  KEY `mcp_tokens_client_id_index` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mcp_audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `client_id` varchar(64) DEFAULT NULL,
  `tool` varchar(80) NOT NULL,
  `status` varchar(12) NOT NULL,
  `summary` text DEFAULT NULL,
  `duration_ms` int(10) unsigned DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mcp_audit_logs_user_id_index` (`user_id`),
  KEY `mcp_audit_logs_tool_index` (`tool`),
  KEY `mcp_audit_logs_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
