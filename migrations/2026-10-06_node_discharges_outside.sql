-- Adds the Safe point flag used by emergency ("Nearest Exit") routing: a
-- Fire Exit node an admin has confirmed leads outside at ground level. Additive and safe to run before the matching web build is
-- deployed. A fresh database does not need this: load schema.sql instead.
--   mysql -u root arise_web < migrations/2026-10-06_node_discharges_outside.sql
--
-- Every existing node starts unflagged. Entrance nodes are never Safe
-- points, so until an admin ticks real Fire Exit doors, Nearest Exit has no
-- route in a building.

ALTER TABLE `nodes`
  ADD COLUMN `discharges_outside` tinyint(1) NOT NULL DEFAULT 0 AFTER `is_building_entrance`;
