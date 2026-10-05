-- Adds placard_photos: extra Room photos shown after placard_dialogs.photo_path
-- (which stays the main photo, and the only one the mobile app reads). A fresh
-- database does not need this: load schema.sql instead.
--   mysql -u root arise_web < migrations/2026-10-08_placard_photos.sql

CREATE TABLE IF NOT EXISTS `placard_photos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `placard_dialog_id` int(10) unsigned NOT NULL,
  `photo_path` varchar(500) NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `placard_dialog_id` (`placard_dialog_id`),
  CONSTRAINT `placard_photos_ibfk_1` FOREIGN KEY (`placard_dialog_id`) REFERENCES `placard_dialogs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
