-- Offboarding module. Additive.
-- Easiest way: v2 portal -> System settings -> "Update database". Use this file only if you prefer phpMyAdmin.
-- Run it ONCE (the ALTER fails with "Duplicate column" if repeated; the System-settings updater skips what exists).
-- Deploying the Laravel code BEFORE this is harmless: the offboarding screen just says the update is needed.

CREATE TABLE IF NOT EXISTS `offboardings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'Scheduled',
  `reason_type` varchar(40) NOT NULL DEFAULT 'Terminated',
  `reason` text DEFAULT NULL,
  `last_working_day` date NOT NULL,
  `removal_date` date NOT NULL,
  `previous_hire_end_date` date DEFAULT NULL,
  `employee_name` varchar(150) DEFAULT NULL,
  `employee_email` varchar(190) DEFAULT NULL,
  `employee_type` varchar(60) DEFAULT NULL,
  `employee_team` varchar(120) DEFAULT NULL,
  `joining_date` date DEFAULT NULL,
  `initiated_by` bigint(20) unsigned DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `offboardings_user_id_index` (`user_id`),
  KEY `offboardings_status_index` (`status`),
  KEY `offboardings_removal_date_index` (`removal_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `offboarding_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `offboarding_id` bigint(20) unsigned NOT NULL,
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(40) NOT NULL,
  `detail` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `offboarding_logs_offboarding_id_index` (`offboarding_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `payslips` ADD COLUMN `pay_notes` text DEFAULT NULL;
