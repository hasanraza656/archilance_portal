-- Employee "hire end date" (last day / contract end). Optional, informational.
-- Easiest way: v2 portal -> System settings -> "Update database". Use this file only if you prefer phpMyAdmin.
-- Run it ONCE: the ALTER fails with "Duplicate column" if repeated (the System-settings updater skips what exists).
-- Deploying the Laravel code BEFORE this is harmless: the field is simply ignored until the column exists.

ALTER TABLE `users` ADD COLUMN `hire_end_date` date DEFAULT NULL;
