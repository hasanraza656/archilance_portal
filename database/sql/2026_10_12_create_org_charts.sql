-- Saved organization charts (Org Chart module in the v2 portal).
-- Run once in phpMyAdmin (SQL tab). Safe to run twice (IF NOT EXISTS).
--
-- Deploying the Laravel code BEFORE running this is harmless: the endpoints detect
-- the missing table and answer "not available yet" instead of erroring.
--
--   snapshot  JSON: every person on the chart (parent, position, category, texts)
--   changes   JSON: what differs from the portal database at the moment of saving

CREATE TABLE IF NOT EXISTS `org_charts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `node_count` int(11) NOT NULL DEFAULT 0,
  `change_count` int(11) NOT NULL DEFAULT 0,
  `snapshot` longtext NOT NULL,
  `changes` longtext DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
