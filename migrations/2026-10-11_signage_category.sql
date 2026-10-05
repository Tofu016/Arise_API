-- Adds signage_slides.category: which kiosk surface an advertisement plays on.
-- 'footer' is the bottom whitespace under the panorama, 'starting' is the
-- 16:9 rectangle on the kiosk starting screen. Existing rows are footer ads.
-- Two slides may share one media file (one per category, or both); the file is
-- deleted only when the last slide pointing at it goes. A fresh database does
-- not need this: load schema.sql instead.
--   mysql -u root arise_web < migrations/2026-10-11_signage_category.sql

ALTER TABLE `signage_slides`
  ADD COLUMN `category` enum('footer','starting') NOT NULL DEFAULT 'footer' AFTER `title`;
