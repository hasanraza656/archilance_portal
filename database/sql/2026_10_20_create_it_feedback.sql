-- IT team feedback (v2 portal). Run once in phpMyAdmin (SQL tab). Safe to run twice (IF NOT EXISTS).
-- Or skip this file and use System settings -> Update database in v2.
--
-- Deploying the Laravel code BEFORE this is harmless: the feedback endpoints detect the
-- missing table and report "not available yet", so no popup appears and nothing errors.
--
--   status   'skipped' (dismissed the popup) or 'submitted'
--   one row per user (unique user_id)

CREATE TABLE IF NOT EXISTS `it_feedback` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'skipped',
  `overall` tinyint(3) unsigned DEFAULT NULL,
  `satisfaction` tinyint(3) unsigned DEFAULT NULL,
  `communication` tinyint(3) unsigned DEFAULT NULL,
  `response` tinyint(3) unsigned DEFAULT NULL,
  `resolution` tinyint(3) unsigned DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `skipped_at` timestamp NULL DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `it_feedback_user_id_unique` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
