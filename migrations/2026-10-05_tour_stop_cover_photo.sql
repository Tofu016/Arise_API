-- Adds an optional cover photo to each tour stop (a flat photo, stored in
-- tourcover/ like a section's cover), shown on the public tour's scene
-- list instead of a crop of the stop's 360° panorama. A fresh database
-- does not need this: load schema.sql instead. Safe to run once on an
-- existing database; it only adds a nullable column.
--
--   mysql -u root arise_web < migrations/2026-10-05_tour_stop_cover_photo.sql

ALTER TABLE `tour_stops`
  ADD COLUMN `cover_photo_path` varchar(500) DEFAULT NULL AFTER `photo_path`;
