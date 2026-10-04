-- Renames the node and marker type ids to match their labels, and renames
-- every node whose id follows the generated convention
-- {building}_f{floor}_{type}{NN} so the type part matches the new type id.
--
--   node type   transition      -> stairs
--               transitionExit  -> fire_exit
--               openArea        -> open_area
--               portal          -> building_transition
--   marker type exit            -> emergency_exit
--               hydrant         -> fire_extinguisher
--   node id     gd1_f2_transition01 -> gd1_f2_stairs01 (and the same for the
--               other types above)
--
-- A node id that does not follow the convention (for example
-- gd2_f6_hallway03 holding a Stairs node, or a hand-typed name) is left
-- alone, as is any rename whose new id is already taken.
--
-- Take a backup first, then deploy the matching API and web code together:
-- the old code does not know the new type ids. A fresh database does not
-- need this: load schema.sql instead.
--
--   mysqldump -u root arise_web > backup-before-rename.sql
--   mysql -u root arise_web < migrations/2026-10-05_rename_type_ids.sql

-- 1. Marker types. The enum is widened first so both spellings are valid
-- while the rows move, then narrowed to the final set.
ALTER TABLE `node_markers`
  MODIFY `type` enum('room','facility','exit','hydrant','elevator','emergency_exit','fire_extinguisher') NOT NULL;
UPDATE `node_markers` SET `type` = 'emergency_exit' WHERE `type` = 'exit';
UPDATE `node_markers` SET `type` = 'fire_extinguisher' WHERE `type` = 'hydrant';
ALTER TABLE `node_markers`
  MODIFY `type` enum('room','facility','emergency_exit','fire_extinguisher','elevator') NOT NULL;

-- 2. Node ids. Worked out from the old types, before they change.
DROP TABLE IF EXISTS `tmp_type_map`;
CREATE TABLE `tmp_type_map` (
  `old_type` varchar(64) NOT NULL,
  `new_type` varchar(64) NOT NULL,
  `old_slug` varchar(64) NOT NULL,
  `new_slug` varchar(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `tmp_type_map` VALUES
  ('transition', 'stairs', 'transition', 'stairs'),
  ('transitionExit', 'fire_exit', 'transition_exit', 'fire_exit'),
  ('openArea', 'open_area', 'open_area', 'open_area'),
  ('portal', 'building_transition', 'portal', 'building_transition');

DROP TABLE IF EXISTS `tmp_node_renames`;
CREATE TABLE `tmp_node_renames` (
  `old_id` varchar(64) NOT NULL,
  `new_id` varchar(64) NOT NULL,
  PRIMARY KEY (`old_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `tmp_node_renames` (`old_id`, `new_id`)
SELECT n.`id`,
       CONCAT(n.`building`, '_f', n.`floor`, '_', m.`new_slug`,
              SUBSTRING(n.`id`, CHAR_LENGTH(CONCAT(n.`building`, '_f', n.`floor`, '_', m.`old_slug`)) + 1))
FROM `nodes` n
JOIN `tmp_type_map` m ON m.`old_type` = n.`type`
WHERE m.`old_slug` <> m.`new_slug`
  AND n.`id` LIKE CONCAT(n.`building`, '_f', n.`floor`, '_', m.`old_slug`, '%')
  AND SUBSTRING(n.`id`, CHAR_LENGTH(CONCAT(n.`building`, '_f', n.`floor`, '_', m.`old_slug`)) + 1) REGEXP '^[0-9]+$';

-- A rename whose new id already belongs to another node is dropped.
DELETE r FROM `tmp_node_renames` r
JOIN `nodes` taken ON taken.`id` = r.`new_id`;

-- 3. kiosks.node_id has no ON UPDATE CASCADE, so a kiosk parked on a node
-- that is about to be renamed is detached first and pointed at the new id after.
DROP TABLE IF EXISTS `tmp_kiosk_restore`;
CREATE TABLE `tmp_kiosk_restore` (
  `kiosk_id` int(10) unsigned NOT NULL,
  `new_node_id` varchar(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO `tmp_kiosk_restore` (`kiosk_id`, `new_node_id`)
SELECT k.`id`, r.`new_id` FROM `kiosks` k JOIN `tmp_node_renames` r ON r.`old_id` = k.`node_id`;
UPDATE `kiosks` k JOIN `tmp_kiosk_restore` t ON t.`kiosk_id` = k.`id` SET k.`node_id` = NULL;

-- 4. The rename itself. node_markers, node_neighbors and node_rooms follow
-- through their ON UPDATE CASCADE foreign keys.
UPDATE `nodes` n JOIN `tmp_node_renames` r ON r.`old_id` = n.`id` SET n.`id` = r.`new_id`;

UPDATE `kiosks` k JOIN `tmp_kiosk_restore` t ON t.`kiosk_id` = k.`id` SET k.`node_id` = t.`new_node_id`;

-- analytics_events has no foreign key to nodes; keep its history pointing at
-- the same nodes.
UPDATE `analytics_events` e JOIN `tmp_node_renames` r ON r.`old_id` = e.`node_id` SET e.`node_id` = r.`new_id`;
UPDATE `analytics_events` e JOIN `tmp_node_renames` r ON r.`old_id` = e.`from_node_id` SET e.`from_node_id` = r.`new_id`;
UPDATE `analytics_events` e JOIN `tmp_node_renames` r ON r.`old_id` = e.`to_node_id` SET e.`to_node_id` = r.`new_id`;

-- 5. Node types.
UPDATE `nodes` n JOIN `tmp_type_map` m ON m.`old_type` = n.`type` SET n.`type` = m.`new_type`;

DROP TABLE `tmp_kiosk_restore`;
DROP TABLE `tmp_node_renames`;
DROP TABLE `tmp_type_map`;
