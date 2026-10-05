
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `analytics_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `analytics_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `session_id` char(36) NOT NULL,
  `event_type` enum('stage_reached','room_searched','go_to','directions_requested','move','feedback_submitted','session_end') NOT NULL,
  `stage` varchar(32) DEFAULT NULL,
  `node_id` varchar(64) DEFAULT NULL,
  `from_node_id` varchar(64) DEFAULT NULL,
  `to_node_id` varchar(64) DEFAULT NULL,
  `room_query` varchar(255) DEFAULT NULL,
  `matched` tinyint(1) DEFAULT NULL,
  `move_kind` enum('walk','jump') DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `session_id` (`session_id`),
  KEY `event_type_created` (`event_type`,`created_at`),
  KEY `from_to` (`from_node_id`,`to_node_id`),
  CONSTRAINT `analytics_events_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `analytics_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `analytics_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `analytics_sessions` (
  `id` char(36) NOT NULL,
  `platform` enum('kiosk','web') NOT NULL,
  `campus` varchar(64) DEFAULT NULL,
  `building` varchar(64) DEFAULT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ended_at` datetime DEFAULT NULL,
  `end_reason` enum('feedback','idle_timeout','inactivity_timeout') DEFAULT NULL,
  `furthest_stage` enum('start','campus','building','floor','exploring','feedback') NOT NULL DEFAULT 'start',
  `gave_feedback` tinyint(1) NOT NULL DEFAULT 0,
  `feedback_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `platform_started` (`platform`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `app_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `app_feedback` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `rating` tinyint(3) unsigned NOT NULL,
  `comment` text DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `auth_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `auth_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int(10) unsigned NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `admin_id` (`admin_id`),
  KEY `idx_auth_tokens_hash` (`token_hash`),
  CONSTRAINT `auth_tokens_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `buildings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `buildings` (
  `id` varchar(64) NOT NULL,
  `name` varchar(255) NOT NULL,
  `floor_count` int(10) unsigned NOT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `campus_id` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `elevators`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `elevators` (
  `id` varchar(64) NOT NULL,
  `label` varchar(255) NOT NULL,
  `building` varchar(64) NOT NULL,
  `accessible_floors` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `building` (`building`),
  CONSTRAINT `elevators_ibfk_1` FOREIGN KEY (`building`) REFERENCES `buildings` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `kiosks`;
CREATE TABLE `kiosks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `node_id` varchar(64) DEFAULT NULL,
  `pairing_code_hash` char(64) DEFAULT NULL,
  `pairing_expires_at` datetime DEFAULT NULL,
  `token_hash` char(64) DEFAULT NULL,
  `paired_at` datetime DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_kiosks_code` (`pairing_code_hash`),
  KEY `idx_kiosks_token` (`token_hash`),
  KEY `node_id` (`node_id`),
  CONSTRAINT `kiosks_ibfk_1` FOREIGN KEY (`node_id`) REFERENCES `nodes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TABLE IF EXISTS `kiosk_pair_failures`;
CREATE TABLE `kiosk_pair_failures` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pair_failures_ip` (`ip`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TABLE IF EXISTS `rate_limit_hits`;
CREATE TABLE `rate_limit_hits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `bucket` varchar(32) NOT NULL,
  `subject` char(64) NOT NULL,
  `hit_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rate_limit_lookup` (`bucket`,`subject`,`hit_at`),
  KEY `idx_rate_limit_hit_at` (`hit_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DROP TABLE IF EXISTS `node_markers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `node_markers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `node_id` varchar(64) NOT NULL,
  `type` enum('room','facility','emergency_exit','fire_extinguisher','elevator') NOT NULL,
  `label` varchar(255) NOT NULL,
  `yaw` float NOT NULL,
  `pitch` float NOT NULL,
  `elevator_id` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `node_markers_ibfk_1` (`node_id`),
  KEY `node_markers_ibfk_2` (`elevator_id`),
  CONSTRAINT `node_markers_ibfk_1` FOREIGN KEY (`node_id`) REFERENCES `nodes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `node_markers_ibfk_2` FOREIGN KEY (`elevator_id`) REFERENCES `elevators` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `node_marker_landings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `node_marker_landings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `marker_id` int(10) unsigned NOT NULL,
  `landing_node_id` varchar(64) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_marker_landing` (`marker_id`,`landing_node_id`),
  KEY `node_marker_landings_ibfk_2` (`landing_node_id`),
  CONSTRAINT `node_marker_landings_ibfk_1` FOREIGN KEY (`marker_id`) REFERENCES `node_markers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `node_marker_landings_ibfk_2` FOREIGN KEY (`landing_node_id`) REFERENCES `nodes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `node_neighbors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `node_neighbors` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `node_id` varchar(64) NOT NULL,
  `neighbor_id` varchar(64) NOT NULL,
  `yaw` float NOT NULL,
  `pitch` float NOT NULL,
  `default_yaw` float DEFAULT NULL,
  `default_pitch` float DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_node_edge` (`node_id`,`neighbor_id`),
  KEY `node_neighbors_ibfk_2` (`neighbor_id`),
  CONSTRAINT `node_neighbors_ibfk_1` FOREIGN KEY (`node_id`) REFERENCES `nodes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `node_neighbors_ibfk_2` FOREIGN KEY (`neighbor_id`) REFERENCES `nodes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `node_rooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `node_rooms` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `node_id` varchar(64) NOT NULL,
  `room_name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `node_rooms_ibfk_1` (`node_id`),
  CONSTRAINT `node_rooms_ibfk_1` FOREIGN KEY (`node_id`) REFERENCES `nodes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `nodes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nodes` (
  `id` varchar(64) NOT NULL,
  `name` varchar(255) NOT NULL,
  `building` varchar(64) NOT NULL,
  `floor` int(11) NOT NULL,
  `type` varchar(64) NOT NULL,
  `is_starting_node` tinyint(1) NOT NULL DEFAULT 0,
  `starting_view_yaw` float DEFAULT NULL,
  `starting_view_pitch` float DEFAULT NULL,
  `is_campus_entrance` tinyint(1) NOT NULL DEFAULT 0,
  `is_building_entrance` tinyint(1) NOT NULL DEFAULT 0,
  `is_emergency_destination` tinyint(1) NOT NULL DEFAULT 0,
  `photo_path` varchar(500) DEFAULT NULL,
  `flowchart_position_x` float DEFAULT NULL,
  `flowchart_position_y` float DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `building` (`building`),
  CONSTRAINT `nodes_ibfk_1` FOREIGN KEY (`building`) REFERENCES `buildings` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `placard_dialogs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `placard_dialogs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `room_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `department` varchar(255) DEFAULT NULL,
  `use` varchar(255) DEFAULT NULL,
  `photo_path` varchar(500) DEFAULT NULL,
  `photo_360_path` varchar(500) DEFAULT NULL,
  `thumb_x` tinyint(3) unsigned NOT NULL DEFAULT 50,
  `thumb_y` tinyint(3) unsigned NOT NULL DEFAULT 50,
  `link` varchar(500) DEFAULT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `room_name` (`room_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `placard_photos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `placard_photos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `placard_dialog_id` int(10) unsigned NOT NULL,
  `photo_path` varchar(500) NOT NULL,
  `thumb_x` tinyint(3) unsigned NOT NULL DEFAULT 50,
  `thumb_y` tinyint(3) unsigned NOT NULL DEFAULT 50,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `placard_dialog_id` (`placard_dialog_id`),
  CONSTRAINT `placard_photos_ibfk_1` FOREIGN KEY (`placard_dialog_id`) REFERENCES `placard_dialogs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `placard_search_terms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `placard_search_terms` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `placard_dialog_id` int(10) unsigned NOT NULL,
  `term` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `placard_dialog_id` (`placard_dialog_id`),
  KEY `idx_search_term` (`term`),
  CONSTRAINT `placard_search_terms_ibfk_1` FOREIGN KEY (`placard_dialog_id`) REFERENCES `placard_dialogs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `signage_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `signage_settings` (
  `id` tinyint(3) unsigned NOT NULL,
  `rotation_order` enum('sequence','shuffle') NOT NULL DEFAULT 'sequence',
  `transition` enum('fade','cut') NOT NULL DEFAULT 'fade',
  `default_duration_seconds` decimal(4,1) NOT NULL DEFAULT 10.0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `signage_slides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `signage_slides` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `category` enum('footer','starting') NOT NULL DEFAULT 'footer',
  `media_path` varchar(500) NOT NULL,
  `crop_x` decimal(7,6) NOT NULL DEFAULT 0.000000,
  `crop_y` decimal(7,6) NOT NULL DEFAULT 0.000000,
  `crop_w` decimal(7,6) NOT NULL DEFAULT 1.000000,
  `crop_h` decimal(7,6) NOT NULL DEFAULT 1.000000,
  `duration_seconds` decimal(4,1) NOT NULL DEFAULT 10.0,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `active_order` (`is_active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admins` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `status` enum('pending','approved') NOT NULL DEFAULT 'approved',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `admin_id` (`admin_id`),
  CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

