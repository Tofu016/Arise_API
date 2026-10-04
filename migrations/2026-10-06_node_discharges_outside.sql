-- Adds the Safe point flag used by emergency ("Nearest Exit") routing: an
-- Entrance or Fire Exit node an admin has confirmed leads outside at ground
-- level. Additive and safe to run before the matching web build is
-- deployed. A fresh database does not need this: load schema.sql instead.
--   mysql -u root arise_web < migrations/2026-10-06_node_discharges_outside.sql
--
-- Every existing node starts unflagged, so until an admin flags real exits
-- the web app falls back to each building's Entrance nodes.

ALTER TABLE `nodes`
  ADD COLUMN `discharges_outside` tinyint(1) NOT NULL DEFAULT 0 AFTER `is_building_entrance`;
