-- Offboarding: the day the notice was given. Additive.
-- Easiest way: v2 portal -> System settings -> "Update database". Use this file only if you prefer phpMyAdmin.
-- Run it ONCE (the ALTER fails with "Duplicate column" if repeated; the System-settings updater skips what exists).
-- Deploying the Laravel code BEFORE this is harmless: the notice date is simply not stored until the column exists.

ALTER TABLE `offboardings` ADD COLUMN `notice_date` date DEFAULT NULL;
