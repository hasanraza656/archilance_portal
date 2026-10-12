-- Project managers: mark which assignees of a FIRST-LEVEL task manage it.
-- (In the portal a "job" is a row of `projects`; a job's first-level tasks —
-- parent_task_id IS NULL — are what the portal calls "projects".)
--
-- Run once in phpMyAdmin (SQL tab) on the live database if you cannot run
-- `php artisan migrate`. Safe to run twice (IF NOT EXISTS).
--
-- Deploying the Laravel code BEFORE running this is harmless: the endpoints
-- detect the missing column and report "not available yet", and the existing
-- assignee screens keep working exactly as before.

ALTER TABLE `task_assignees`
  ADD COLUMN IF NOT EXISTS `is_manager` tinyint(1) NOT NULL DEFAULT 0 AFTER `task_id`;

-- Optional clean-up: the earlier job-level attempt added this column to
-- `project_assignees`. Nothing uses it any more, so it can be removed:
-- ALTER TABLE `project_assignees` DROP COLUMN IF EXISTS `is_manager`;
