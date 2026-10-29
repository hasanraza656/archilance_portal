-- Experience letters: the code behind the QR on each letter (public verification page).
-- Easiest way: v2 portal -> System settings -> "Update database". Use this file only if you prefer phpMyAdmin.
-- Run ONCE (the ALTER fails with "Duplicate column" if repeated). Run it AFTER 2026_10_27_experience_letters.sql.
-- Deploying the Laravel code BEFORE this is harmless: letters just have no QR link until the column exists.

ALTER TABLE `experience_letters` ADD COLUMN `verify_token` varchar(64) DEFAULT NULL, ADD UNIQUE KEY `experience_letters_verify_token_unique` (`verify_token`);
UPDATE `experience_letters` SET `verify_token` = SUBSTRING(REPLACE(UUID(), '-', ''), 1, 32) WHERE `verify_token` IS NULL;
