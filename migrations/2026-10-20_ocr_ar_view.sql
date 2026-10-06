-- OCR Management: placard_ocr_photos are a room's own 360 images for the
-- mobile app's AR portal after a placard scan, in the order an admin sorted
-- them (the visitor pages through them), uploaded on the OCR Management page.
-- They are separate from the room's photos (placard_photos), which the room
-- card's 360 VIEW keeps showing; a room on OCR with none shows the bundled
-- placeholder. ocr_settings holds the message shown at the top of the
-- placard scanner screen: one row (id 1), no seed row needed, a missing row
-- means no message. Purely additive: older builds ignore both.
--   mysql -u root arise_web < migrations/2026-10-20_ocr_ar_view.sql

CREATE TABLE `placard_ocr_photos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `placard_dialog_id` int(10) unsigned NOT NULL,
  `photo_path` varchar(500) NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `placard_dialog_id` (`placard_dialog_id`),
  CONSTRAINT `placard_ocr_photos_ibfk_1` FOREIGN KEY (`placard_dialog_id`) REFERENCES `placard_dialogs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `ocr_settings` (
  `id` tinyint(3) unsigned NOT NULL,
  `scanner_message` varchar(300) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
