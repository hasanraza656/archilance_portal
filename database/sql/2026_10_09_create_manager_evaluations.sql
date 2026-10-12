-- Bi-weekly manager / executive ratings (who rates whom is worked out by the app).
-- `month_start` holds the first day (a Monday) of the 14-day rating period — the column
-- keeps its original name so nothing needs renaming on a database that already has it.
-- Run once in phpMyAdmin (SQL tab). Safe to run twice (IF NOT EXISTS).
--
-- Deploying the Laravel code BEFORE running this is harmless: the monthly rating
-- endpoints detect the missing table and report "nothing pending".
--
-- One row = one person's rating of one manager/executive for one month.
--   kind     team | exec_to_manager | manager_to_exec | peer
--   skipped  1 when the rater chose "I can't rate this person" (no scores)
--   scores   JSON object {question_key: 1..10}
--   average  mean of the scores (NULL when skipped)

CREATE TABLE IF NOT EXISTS `manager_evaluations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rater_id` bigint(20) unsigned NOT NULL,
  `ratee_id` bigint(20) unsigned NOT NULL,
  `month_start` date NOT NULL,
  `kind` varchar(32) NOT NULL,
  `skipped` tinyint(1) NOT NULL DEFAULT 0,
  `scores` longtext DEFAULT NULL,
  `average` decimal(4,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `manager_evaluations_unique_entry` (`rater_id`,`ratee_id`,`month_start`),
  KEY `manager_evaluations_ratee_month` (`ratee_id`,`month_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
