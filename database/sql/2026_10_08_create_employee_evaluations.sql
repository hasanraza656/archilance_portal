-- Weekly employee evaluations table (one overall evaluation per employee per week,
-- six criteria scored 1-10).
--
-- Run this once in phpMyAdmin (SQL tab) on the live database if you cannot run
-- `php artisan migrate`. Safe to run twice (IF NOT EXISTS).
--
-- >>> If you ALREADY created the earlier version of this table (the one with
-- >>> project_id / star columns) while testing, run
-- >>> 2026_10_08_upgrade_employee_evaluations_v2.sql FIRST — it drops the old
-- >>> table (test data only) — and then this file.
--
-- Deploying the Laravel code BEFORE running this is harmless: the evaluation
-- endpoints detect the missing table and simply report "nothing pending".

CREATE TABLE IF NOT EXISTS `employee_evaluations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `manager_id` bigint(20) unsigned NOT NULL,
  `employee_id` bigint(20) unsigned NOT NULL,
  `week_start` date NOT NULL,
  `quality` tinyint(3) unsigned NOT NULL,
  `timely_delivery` tinyint(3) unsigned NOT NULL,
  `communication` tinyint(3) unsigned NOT NULL,
  `understanding` tinyint(3) unsigned NOT NULL,
  `ownership_initiative` tinyint(3) unsigned NOT NULL,
  `productivity` tinyint(3) unsigned NOT NULL,
  `comments` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_evaluations_unique_entry` (`employee_id`,`manager_id`,`week_start`),
  KEY `employee_evaluations_manager_week` (`manager_id`,`week_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional: record the migration so a later `php artisan migrate` skips it.
-- (The migration is also guarded with hasTable(), so skipping this is fine.)
-- INSERT INTO `migrations` (`migration`, `batch`)
-- SELECT '2026_10_08_100000_create_employee_evaluations_table', COALESCE(MAX(`batch`),0)+1 FROM `migrations`;
