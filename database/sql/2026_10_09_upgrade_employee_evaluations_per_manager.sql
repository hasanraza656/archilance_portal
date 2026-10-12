-- Weekly evaluations are now given by every PROJECT MANAGER an employee worked
-- for, so one employee can have several evaluations in the same week (one per
-- manager). The unique key therefore has to include the manager.
--
-- Run once in phpMyAdmin (SQL tab). Safe to run on a table that holds test data
-- or real ratings — no rows are changed, only the index.
--
-- If you run it twice, the second run reports "Can't DROP ... check that it
-- exists" for the old key: that is harmless, ignore it.

ALTER TABLE `employee_evaluations` DROP INDEX `employee_evaluations_unique_entry`;

ALTER TABLE `employee_evaluations`
  ADD UNIQUE KEY `employee_evaluations_unique_entry` (`employee_id`, `manager_id`, `week_start`);
