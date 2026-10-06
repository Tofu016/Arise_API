-- OCR Management: which rooms and facilities the mobile placard scanner may
-- match, the Placard name their search terms are generated from, and which
-- search terms an admin typed in by hand (is_extra) rather than generated.
-- Additive: older web and mobile builds ignore the new columns.
--
-- Every room or facility that already has a search term was matchable by the
-- scanner before this, so it starts out eligible, with its room name as its
-- Placard name. Its existing terms stay as generated terms; the OCR
-- Management page offers to regenerate them in the current format.
--   mysql -u root arise_web < migrations/2026-10-16_ocr_placard_names.sql

ALTER TABLE `placard_dialogs`
  ADD COLUMN `ocr_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `contact_number`,
  ADD COLUMN `placard_name` varchar(255) DEFAULT NULL AFTER `ocr_enabled`;

ALTER TABLE `placard_search_terms`
  ADD COLUMN `is_extra` tinyint(1) NOT NULL DEFAULT 0 AFTER `term`;

UPDATE `placard_dialogs` d
  SET d.`ocr_enabled` = 1, d.`placard_name` = d.`room_name`
  WHERE EXISTS (SELECT 1 FROM `placard_search_terms` t WHERE t.`placard_dialog_id` = d.`id`);
