-- Experience letters (generated certificates). Additive: creates one new table, touches nothing else.
-- Easiest way: v2 portal -> System settings -> "Update database". Use this file only if you prefer phpMyAdmin.
-- Safe to run twice (CREATE TABLE IF NOT EXISTS).
-- Deploying the Laravel code BEFORE this is harmless: the Experience Letters page just says the update is pending.

CREATE TABLE IF NOT EXISTS `experience_letters` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reference_no` varchar(40) NOT NULL,
  `employee_id` bigint(20) unsigned NOT NULL,
  `employee_name` varchar(150) NOT NULL,
  `designation` varchar(150) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `issue_date` date NOT NULL,
  `projects_count` int(10) unsigned NOT NULL DEFAULT 0,
  `data` longtext NOT NULL,
  `source` varchar(12) NOT NULL DEFAULT 'portal',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `experience_letters_reference_no_unique` (`reference_no`),
  KEY `experience_letters_employee_id_index` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
