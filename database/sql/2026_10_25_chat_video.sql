-- Chat video (screen recordings, video duration, lower-quality copies for slow connections).
-- Easiest way: v2 portal -> System settings -> "Update database". Use this file only if you prefer phpMyAdmin.
-- Run it ONCE: the ALTER fails with "Duplicate column" if repeated (the System-settings updater skips what exists).
-- Deploying the Laravel code BEFORE this is harmless: videos still send and play, just without duration / quality copies.

CREATE TABLE IF NOT EXISTS `chat_attachment_variants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `attachment_id` bigint(20) unsigned NOT NULL,
  `label` varchar(12) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `mime` varchar(100) DEFAULT NULL,
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `width` int(10) unsigned DEFAULT NULL,
  `height` int(10) unsigned DEFAULT NULL,
  `bitrate` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chat_attachment_variants_attachment_id_label_unique` (`attachment_id`,`label`),
  KEY `chat_attachment_variants_attachment_id_index` (`attachment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `chat_attachments` ADD COLUMN `duration_ms` int(10) unsigned DEFAULT NULL;
