-- One ordered photo list per room or facility, each photo marked flat or 360.
-- placard_photos gains `kind`; every existing flat photo (the main photo and
-- the extras) is deleted and every existing 360 photo becomes a '360' row, so
-- placard_dialogs loses photo_path, photo_360_path and the main thumb focus.
-- The API still returns photo_path / photo_360_path (first flat / first 360)
-- so the mobile app keeps working unchanged. A fresh database does not need
-- this: load schema.sql instead.
--   mysql -u root arise_web < migrations/2026-10-15_room_photo_kinds.sql
--   php index.php Photos_CLI purgeRoomPhotos
-- Run the second command once, straight after the first and before any new
-- upload: it deletes the room photo files that nothing references any more.

ALTER TABLE `placard_photos`
  ADD COLUMN `kind` enum('flat','360') NOT NULL DEFAULT 'flat' AFTER `photo_path`;

DELETE FROM `placard_photos`;

INSERT INTO `placard_photos` (`placard_dialog_id`, `photo_path`, `kind`, `sort_order`)
  SELECT `id`, `photo_360_path`, '360', 0
  FROM `placard_dialogs`
  WHERE `photo_360_path` IS NOT NULL AND `photo_360_path` <> '';

ALTER TABLE `placard_dialogs`
  DROP COLUMN `photo_path`,
  DROP COLUMN `photo_360_path`,
  DROP COLUMN `thumb_x`,
  DROP COLUMN `thumb_y`;
