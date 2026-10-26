-- Chat v2 (groups, threads, pins/mutes, presence, big uploads, thumbnails).
-- Easiest way: v2 portal -> System settings -> "Update database". Use this file only if you prefer phpMyAdmin.
-- Every statement is additive. Run it ONCE: the ALTERs below fail with "Duplicate column" if repeated
-- (the System-settings updater skips what already exists, so it is the safe way to re-run).
-- Deploying the Laravel code BEFORE this is harmless: chat v2 endpoints answer "not available yet".

CREATE TABLE IF NOT EXISTS `chat_groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `avatar_path` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_group_members` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `role` varchar(10) NOT NULL DEFAULT 'member',
  `color` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `added_by` bigint(20) unsigned DEFAULT NULL,
  `last_read_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `joined_at` timestamp NULL DEFAULT NULL,
  `left_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chat_group_members_group_id_user_id_unique` (`group_id`,`user_id`),
  KEY `chat_group_members_group_id_index` (`group_id`),
  KEY `chat_group_members_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_peer_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `peer_type` varchar(8) NOT NULL,
  `peer_id` bigint(20) unsigned NOT NULL,
  `pinned_at` timestamp NULL DEFAULT NULL,
  `muted` tinyint(1) NOT NULL DEFAULT 0,
  `muted_until` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chat_peer_settings_user_id_peer_type_peer_id_unique` (`user_id`,`peer_type`,`peer_id`),
  KEY `chat_peer_settings_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_presence` (
  `user_id` bigint(20) unsigned NOT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `last_digest_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_uploads` (
  `id` varchar(40) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `mime` varchar(120) DEFAULT NULL,
  `file_size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `remote_path` varchar(255) NOT NULL,
  `upload_url` text DEFAULT NULL,
  `bytes_received` bigint(20) unsigned NOT NULL DEFAULT 0,
  `status` varchar(12) NOT NULL DEFAULT 'open',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `chat_uploads_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `chats`
  ADD COLUMN `group_id` bigint(20) unsigned DEFAULT NULL,
  ADD COLUMN `thread_root_id` bigint(20) unsigned DEFAULT NULL,
  ADD COLUMN `is_system` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN `edited_at` timestamp NULL DEFAULT NULL,
  ADD KEY `chats_group_id_index` (`group_id`),
  ADD KEY `chats_thread_root_id_index` (`thread_root_id`);

ALTER TABLE `chat_attachments`
  ADD COLUMN `thumb_path` varchar(255) DEFAULT NULL,
  ADD COLUMN `width` int(10) unsigned DEFAULT NULL,
  ADD COLUMN `height` int(10) unsigned DEFAULT NULL,
  ADD COLUMN `pages` int(10) unsigned DEFAULT NULL;
