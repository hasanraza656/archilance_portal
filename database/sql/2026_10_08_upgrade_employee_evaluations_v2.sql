-- ONE-TIME upgrade for anyone who already created the FIRST version of
-- `employee_evaluations` (per-project, 5-star columns) while testing.
--
-- The table changed shape (one evaluation per employee per week, six 1-10
-- scores, no project), so the old table cannot be altered cleanly. This DROPS it
-- — it only ever held test evaluations. Run this, THEN run
-- 2026_10_08_create_employee_evaluations.sql.
--
-- Skip this file entirely if you never created the old table.

DROP TABLE IF EXISTS `employee_evaluations`;
