-- Manual time attachments. Additive.
-- Easiest way: v2 portal -> System settings -> "Update database". Use this file only if you prefer phpMyAdmin.
-- Run it ONCE (the ALTERs fail with "Duplicate column" if repeated; the System-settings updater skips what exists).
-- Deploying the Laravel code BEFORE this is harmless: attachments are simply not stored until the columns exist.

ALTER TABLE `work_sessions` ADD COLUMN `proof_path` varchar(255) DEFAULT NULL;
ALTER TABLE `work_sessions` ADD COLUMN `proof_name` varchar(190) DEFAULT NULL;
ALTER TABLE `work_sessions` ADD COLUMN `proof_type` varchar(120) DEFAULT NULL;
ALTER TABLE `work_sessions` ADD COLUMN `proof_size` int(10) unsigned DEFAULT NULL;
