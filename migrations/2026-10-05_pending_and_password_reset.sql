-- Brings an EXISTING database (already on admins-only) in line with the
-- schema.sql that has account approval and emailed password reset. A fresh
-- database does not need this: load schema.sql instead.
--   mysql -u root arise_web < migrations/2026-10-05_pending_and_password_reset.sql
--
-- Existing admins stay 'approved'. Only accounts made through the
-- registration page start as 'pending'.

ALTER TABLE `admins`
  ADD COLUMN `status` enum('pending','approved') NOT NULL DEFAULT 'approved' AFTER `name`;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `admin_id` (`admin_id`),
  CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
