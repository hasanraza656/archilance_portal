-- Payroll: service providers + payout route. Additive.
-- Easiest way: v2 portal -> System settings -> "Update database". Use this file only if you prefer phpMyAdmin.
-- Run it ONCE (the ALTERs fail with "Duplicate column" if repeated; the System-settings updater skips what exists).
-- Deploying the Laravel code BEFORE this is harmless: the new fields are simply ignored until they exist.

CREATE TABLE IF NOT EXISTS `payroll_payees` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `company` varchar(150) DEFAULT NULL,
  `category` varchar(80) NOT NULL DEFAULT 'Service provider',
  `email` varchar(190) DEFAULT NULL,
  `phone` varchar(60) DEFAULT NULL,
  `country` varchar(80) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'Active',
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payroll_payees_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `salary_profiles` ADD COLUMN `payout_route` varchar(16) NOT NULL DEFAULT 'payoneer';
ALTER TABLE `payslips` ADD COLUMN `payout_route` varchar(16) NOT NULL DEFAULT 'payoneer';
