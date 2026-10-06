-- Adds thumb_fov: how wide, in degrees, a 360 photo's flattened thumbnail looks
-- (smaller zooms in, larger zooms out; 60 to 120). 80 is what every thumbnail
-- showed before, so existing photos look the same. A fresh database does not
-- need this: load schema.sql instead.
--   mysql -u root arise_web < migrations/2026-10-18_photo_thumb_fov.sql

ALTER TABLE `placard_photos`
  ADD COLUMN `thumb_fov` smallint(6) NOT NULL DEFAULT 80 AFTER `thumb_pitch`;
