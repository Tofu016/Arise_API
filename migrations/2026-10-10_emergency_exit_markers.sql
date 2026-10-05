-- Replaces the Fire Exit node type and nodes.leads_to_floors with Emergency
-- Exit markers that carry their own landing nodes.
--   mysql -u root arise_web < migrations/2026-10-10_emergency_exit_markers.sql
-- A fresh database does not need this: load schema.sql instead.
--
-- Before: a node of type fire_exit was a hidden fire stairwell, and the only
-- record of where it came out was an ordinary cross-floor neighbor link plus
-- the leads_to_floors list, which no router ever read.
-- After: a node is a fire exit node if and only if it carries an emergency_exit
-- marker. The node keeps its real type (a hallway stays a hallway). The marker
-- lists its exit landing nodes in node_marker_landings, one directed row per
-- landing, used only by Nearest Exit routing.
--
-- Steps, in order:
--   1. Create node_marker_landings.
--   2. Print what is about to be converted (read it before trusting the rest).
--   3. Purge the old cosmetic emergency_exit markers ("Assembly Point" signs).
--      Nearest Exit uses ticked Emergency Exit Destination Points, not labels.
--   4. For every fire_exit node: add an emergency_exit marker, turn each of its
--      cross-floor links into a landing, drop those links, and give the node
--      the type its id names (e.g. gd1_f2_hallway03 -> hallway), else hallway.
--   5. Drop nodes.leads_to_floors.
--
-- A converted marker's yaw/pitch is the old hotspot toward its first cross-floor
-- neighbor (where the stair door was drawn), or 0/0 for a fire exit door that
-- had no such link. Those need placing by hand: the Emergency Coverage page
-- lists every emergency exit marker without a landing and not on a ticked node.

CREATE TABLE IF NOT EXISTS `node_marker_landings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `marker_id` int(10) unsigned NOT NULL,
  `landing_node_id` varchar(64) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_marker_landing` (`marker_id`,`landing_node_id`),
  KEY `node_marker_landings_ibfk_2` (`landing_node_id`),
  CONSTRAINT `node_marker_landings_ibfk_1` FOREIGN KEY (`marker_id`) REFERENCES `node_markers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `node_marker_landings_ibfk_2` FOREIGN KEY (`landing_node_id`) REFERENCES `nodes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The id's third underscore segment (type), trailing sequence number removed:
-- "gd2_f1_stairs01" -> "stairs", "gd1_f2_hallway_03" -> "hallway".
SELECT n.id, n.name, n.building, n.floor, n.is_emergency_destination AS ticked,
       (SELECT COUNT(*) FROM node_neighbors x JOIN nodes y ON y.id = x.neighbor_id
         WHERE x.node_id = n.id AND y.floor <> n.floor AND y.building = n.building) AS landings,
       (SELECT COUNT(*) FROM node_neighbors x JOIN nodes y ON y.id = x.neighbor_id
         WHERE x.node_id = n.id AND y.type <> 'fire_exit' AND y.floor <> n.floor) AS links_to_non_fire_exit,
       CASE REGEXP_REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(n.id, '_', 3), '_', -1), '[0-9]+$', '')
         WHEN 'hallway' THEN 'hallway' WHEN 'lobby' THEN 'lobby' WHEN 'entrance' THEN 'entrance'
         WHEN 'stairs' THEN 'stairs' WHEN 'openarea' THEN 'open_area' WHEN 'parking' THEN 'parking'
         WHEN 'buildingtransition' THEN 'building_transition' ELSE 'hallway'
       END AS becomes_type
  FROM nodes n WHERE n.type = 'fire_exit' ORDER BY n.building, n.floor, n.id;

DELETE FROM `node_markers` WHERE `type` = 'emergency_exit';

INSERT INTO `node_markers` (`node_id`, `type`, `label`, `yaw`, `pitch`)
SELECT f.id, 'emergency_exit', 'Emergency Exit',
       COALESCE((SELECT x.yaw FROM node_neighbors x JOIN nodes y ON y.id = x.neighbor_id
                  WHERE x.node_id = f.id AND y.floor <> f.floor ORDER BY y.floor, x.neighbor_id LIMIT 1), 0),
       COALESCE((SELECT x.pitch FROM node_neighbors x JOIN nodes y ON y.id = x.neighbor_id
                  WHERE x.node_id = f.id AND y.floor <> f.floor ORDER BY y.floor, x.neighbor_id LIMIT 1), 0)
  FROM nodes f WHERE f.type = 'fire_exit';

INSERT INTO `node_marker_landings` (`marker_id`, `landing_node_id`)
SELECT m.id, x.neighbor_id
  FROM nodes f
  JOIN node_markers m ON m.node_id = f.id AND m.type = 'emergency_exit'
  JOIN node_neighbors x ON x.node_id = f.id
  JOIN nodes y ON y.id = x.neighbor_id
 WHERE f.type = 'fire_exit' AND y.floor <> f.floor AND y.building = f.building;

-- The hidden stairs are no longer ordinary links. Ordinary routing walks
-- neighbors and must not take fire stairs.
DELETE x FROM node_neighbors x
  JOIN nodes a ON a.id = x.node_id
  JOIN nodes b ON b.id = x.neighbor_id
 WHERE a.floor <> b.floor AND (a.type = 'fire_exit' OR b.type = 'fire_exit');

UPDATE `nodes`
   SET `type` = CASE REGEXP_REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(`id`, '_', 3), '_', -1), '[0-9]+$', '')
         WHEN 'hallway' THEN 'hallway' WHEN 'lobby' THEN 'lobby' WHEN 'entrance' THEN 'entrance'
         WHEN 'stairs' THEN 'stairs' WHEN 'openarea' THEN 'open_area' WHEN 'parking' THEN 'parking'
         WHEN 'buildingtransition' THEN 'building_transition' ELSE 'hallway'
       END
 WHERE `type` = 'fire_exit';

ALTER TABLE `nodes` DROP COLUMN `leads_to_floors`;
