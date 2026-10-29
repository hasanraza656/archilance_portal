-- Announcement popups: who has seen which notice. Additive.
-- Easiest way: v2 portal -> System settings -> "Update database". Use this file only if you prefer phpMyAdmin.
-- Deploying the Laravel code BEFORE this is harmless: the popup then remembers "seen" in the browser only.

CREATE TABLE IF NOT EXISTS `announcement_views` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `announcement_key` varchar(80) NOT NULL,
  `seen_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `announcement_views_unique` (`user_id`,`announcement_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
