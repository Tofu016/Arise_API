-- A 360 photo's directory cell thumbnail becomes its own setting, since the cell
-- is a wide rectangle and the room panel's thumbnail is a square: cell_yaw /
-- cell_pitch is the centre of the cell's view and cell_fov how wide it looks, in
-- degrees (60 to 110). They start as a copy of the photo's thumbnail settings,
-- which the directory cell used until now. thumb_fov's range also becomes 60 to
-- 110, so a wider value saved before is brought down. A fresh database does not
-- need this: load schema.sql instead.
--   mysql -u root arise_web < migrations/2026-10-19_photo_cell_view.sql

ALTER TABLE `placard_photos`
  ADD COLUMN `cell_yaw` smallint(6) NOT NULL DEFAULT 0 AFTER `thumb_fov`,
  ADD COLUMN `cell_pitch` smallint(6) NOT NULL DEFAULT 0 AFTER `cell_yaw`,
  ADD COLUMN `cell_fov` smallint(6) NOT NULL DEFAULT 80 AFTER `cell_pitch`;

UPDATE `placard_photos`
  SET `thumb_fov` = LEAST(`thumb_fov`, 110),
      `cell_yaw` = `thumb_yaw`,
      `cell_pitch` = `thumb_pitch`,
      `cell_fov` = LEAST(`thumb_fov`, 110);
