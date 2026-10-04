-- Brings an EXISTING database in line with the admins-only, no-email
-- schema.sql. A fresh database does not need this: load schema.sql instead.
--
-- Back up first (mysqldump). This is destructive and runs once:
--   mysql -u root arise_web < migrations/2026-10-04_admins_only_no_email.sql
--
-- What it does:
--   * deletes every non-admin account ('pending' and 'user' rows) and, via
--     the foreign key, their login tokens
--   * drops the self-service tables: saved_rooms (per-user bookmarks),
--     password_resets and email_queue (the email pipeline)
--   * turns `users` into `admins` (no role column) and points
--     auth_tokens at it as admin_id
-- Existing admins keep their email, name and password.

SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM `users` WHERE `role` <> 'admin';

DROP TABLE IF EXISTS `saved_rooms`;
DROP TABLE IF EXISTS `password_resets`;
DROP TABLE IF EXISTS `email_queue`;

ALTER TABLE `auth_tokens` DROP FOREIGN KEY `auth_tokens_ibfk_1`;
ALTER TABLE `auth_tokens`
  DROP INDEX `user_id`,
  CHANGE `user_id` `admin_id` int(10) unsigned NOT NULL,
  ADD KEY `admin_id` (`admin_id`);

ALTER TABLE `users` DROP COLUMN `role`;
RENAME TABLE `users` TO `admins`;

ALTER TABLE `auth_tokens`
  ADD CONSTRAINT `auth_tokens_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;
