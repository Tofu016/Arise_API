-- Adds thumb_x / thumb_y: where a Room photo's square thumbnail is centered,
-- as CSS object-position percentages (50/50 = the middle of the picture).
-- placard_dialogs holds the main photo's, placard_photos each extra's. A fresh
-- database does not need this: load schema.sql instead.
--   mysql -u root arise_web < migrations/2026-10-09_photo_thumb_focus.sql
--
-- Run 2026-10-08_placard_photos.sql first on a database without that table.

ALTER TABLE `placard_dialogs`
  ADD COLUMN `thumb_x` tinyint(3) unsigned NOT NULL DEFAULT 50 AFTER `photo_360_path`,
  ADD COLUMN `thumb_y` tinyint(3) unsigned NOT NULL DEFAULT 50 AFTER `thumb_x`;

ALTER TABLE `placard_photos`
  ADD COLUMN `thumb_x` tinyint(3) unsigned NOT NULL DEFAULT 50 AFTER `photo_path`,
  ADD COLUMN `thumb_y` tinyint(3) unsigned NOT NULL DEFAULT 50 AFTER `thumb_x`;
