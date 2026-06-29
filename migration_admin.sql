-- Admin panel + listing moderation (run via run_admin_migration.php)

ALTER TABLE `users`
  ADD COLUMN `role` ENUM('user','admin') NOT NULL DEFAULT 'user' AFTER `is_active`;

ALTER TABLE `users`
  ADD COLUMN `kyc_note` TEXT NULL AFTER `kyc_status`;

ALTER TABLE `listings`
  ADD COLUMN `review_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER `status`;

ALTER TABLE `listings`
  ADD COLUMN `review_note` TEXT NULL AFTER `review_status`;

-- Existing listings stay visible
UPDATE `listings` SET `review_status` = 'approved' WHERE `review_status` = 'pending';

-- Promote seed admin if present
UPDATE `users` SET `role` = 'admin' WHERE `email` = 'admin@kalabkala.com' LIMIT 1;
