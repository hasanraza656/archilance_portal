-- Payroll — advanced module (audit trail, recurring items, month closing, proration).
-- Run once in phpMyAdmin (SQL tab). Safe to run twice (IF NOT EXISTS everywhere).
--
-- Deploying the Laravel code BEFORE running this is harmless: every new feature
-- checks that these columns/tables exist and quietly falls back to the old
-- behaviour until they do (the payroll pages then show a "run the SQL" note).
--
-- Nothing here deletes or rewrites existing payroll rows.

SET NAMES utf8mb4;

-- 1. payroll_runs: month closing + when it was fully paid + how it was created
ALTER TABLE `payroll_runs`
  ADD COLUMN IF NOT EXISTS `paid_at`   TIMESTAMP NULL DEFAULT NULL AFTER `approved_at`,
  ADD COLUMN IF NOT EXISTS `closed_at` TIMESTAMP NULL DEFAULT NULL AFTER `paid_at`,
  ADD COLUMN IF NOT EXISTS `closed_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `closed_at`,
  ADD COLUMN IF NOT EXISTS `source`    VARCHAR(16) NOT NULL DEFAULT 'manual' AFTER `closed_by`;   -- manual | auto

-- 2. payslips: per-slip approval + proration + why it is held/skipped
ALTER TABLE `payslips`
  ADD COLUMN IF NOT EXISTS `approved_by`   BIGINT UNSIGNED NULL DEFAULT NULL AFTER `paid_by`,
  ADD COLUMN IF NOT EXISTS `approved_at`   TIMESTAMP NULL DEFAULT NULL AFTER `approved_by`,
  ADD COLUMN IF NOT EXISTS `is_prorated`   TINYINT(1) NOT NULL DEFAULT 0 AFTER `approved_at`,
  ADD COLUMN IF NOT EXISTS `days_payable`  SMALLINT UNSIGNED NULL DEFAULT NULL AFTER `is_prorated`,
  ADD COLUMN IF NOT EXISTS `days_in_month` SMALLINT UNSIGNED NULL DEFAULT NULL AFTER `days_payable`,
  ADD COLUMN IF NOT EXISTS `status_reason` VARCHAR(255) NULL DEFAULT NULL AFTER `days_in_month`;

-- 3. payslip_items: which standing item a line came from (NULL = added by hand)
ALTER TABLE `payslip_items`
  ADD COLUMN IF NOT EXISTS `recurring_item_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `notes`;

-- 4. Standing monthly items per employee (allowances, loan instalments, ...).
--    Applied automatically to every payroll generated while they are active.
CREATE TABLE IF NOT EXISTS `recurring_payroll_items` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `kind`        VARCHAR(16)     NOT NULL DEFAULT 'earning',      -- earning | deduction
  `category`    VARCHAR(80)     NOT NULL DEFAULT 'Allowance',
  `label`       VARCHAR(190)             DEFAULT NULL,
  `amount`      DECIMAL(12,2)   NOT NULL DEFAULT 0.00,           -- always positive
  `start_month` DATE            NOT NULL,                        -- first month it applies (YYYY-MM-01)
  `end_month`   DATE                     DEFAULT NULL,           -- last month it applies; NULL = until stopped
  `is_active`   TINYINT(1)      NOT NULL DEFAULT 1,
  `notes`       TEXT                     DEFAULT NULL,
  `created_by`  BIGINT UNSIGNED          DEFAULT NULL,
  `created_at`  TIMESTAMP NULL           DEFAULT NULL,
  `updated_at`  TIMESTAMP NULL           DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `recurring_payroll_items_user_id_index` (`user_id`),
  KEY `recurring_payroll_items_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Audit trail: who did what, when, to which run / payslip, with before -> after.
CREATE TABLE IF NOT EXISTS `payroll_audit_logs` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payroll_run_id` BIGINT UNSIGNED          DEFAULT NULL,
  `payslip_id`     BIGINT UNSIGNED          DEFAULT NULL,
  `user_id`        BIGINT UNSIGNED          DEFAULT NULL,         -- the employee affected, if any
  `actor_id`       BIGINT UNSIGNED          DEFAULT NULL,         -- who did it (NULL = system / scheduler)
  `action`         VARCHAR(48)     NOT NULL,                      -- e.g. payslip.paid, run.approved
  `from_value`     VARCHAR(190)             DEFAULT NULL,
  `to_value`       VARCHAR(190)             DEFAULT NULL,
  `amount`         DECIMAL(12,2)            DEFAULT NULL,
  `detail`         TEXT                     DEFAULT NULL,
  `created_at`     TIMESTAMP NULL           DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payroll_audit_logs_run_index` (`payroll_run_id`),
  KEY `payroll_audit_logs_payslip_index` (`payslip_id`),
  KEY `payroll_audit_logs_created_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
