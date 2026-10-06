-- Adds, per room photo, the angles an admin picks for a 360 photo (in degrees,
-- the same yaw/pitch as a node's hotspots): view_yaw / view_pitch is where the
-- viewer first looks, thumb_yaw / thumb_pitch is the centre of the flattened
-- still shown in the directory cell and the room panel. A flat photo leaves
-- them at 0. A fresh database does not need this: load schema.sql instead.
--   mysql -u root arise_web < migrations/2026-10-17_photo_360_views.sql

ALTER TABLE `placard_photos`
  ADD COLUMN `view_yaw` smallint(6) NOT NULL DEFAULT 0 AFTER `thumb_y`,
  ADD COLUMN `view_pitch` smallint(6) NOT NULL DEFAULT 0 AFTER `view_yaw`,
  ADD COLUMN `thumb_yaw` smallint(6) NOT NULL DEFAULT 0 AFTER `view_pitch`,
  ADD COLUMN `thumb_pitch` smallint(6) NOT NULL DEFAULT 0 AFTER `thumb_yaw`;
