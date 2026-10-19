-- Payroll approval requests (an executive asks an admin to approve a payroll).
-- Prefer System settings -> Update database; this file is the manual fallback for phpMyAdmin.
-- Safe to run twice (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS `payroll_approval_requests` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payroll_run_id` BIGINT UNSIGNED NOT NULL,
  `requested_by`   BIGINT UNSIGNED          DEFAULT NULL,
  `status`         VARCHAR(24)     NOT NULL DEFAULT 'Pending',   -- Pending | Approved | Changes Requested | Rejected | Superseded
  `message`        TEXT                     DEFAULT NULL,
  `pdf_path`       VARCHAR(255)             DEFAULT NULL,
  `total_net`      DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
  `employee_count` INT UNSIGNED    NOT NULL DEFAULT 0,
  `decided_by`     BIGINT UNSIGNED          DEFAULT NULL,
  `decided_at`     TIMESTAMP NULL           DEFAULT NULL,
  `decision_note`  TEXT                     DEFAULT NULL,
  `emailed_to`     VARCHAR(500)             DEFAULT NULL,
  `email_ok`       TINYINT(1)      NOT NULL DEFAULT 0,
  `created_at`     TIMESTAMP NULL           DEFAULT NULL,
  `updated_at`     TIMESTAMP NULL           DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payroll_approval_requests_run_index` (`payroll_run_id`),
  KEY `payroll_approval_requests_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
