-- Renames nodes.discharges_outside to is_emergency_destination. The flag no
-- longer means "leads outside at ground level": it is the admin's statement
-- that someone who reaches the node is out of danger, and it can now be set on
-- Open Area, Parking, Lobby, Entrance and Fire Exit nodes (Floor 1 or
-- Underground only; the web app enforces both). A fresh database does not need
-- this: load schema.sql instead.
--   mysql -u root arise_web < migrations/2026-10-07_rename_discharges_outside.sql
--
-- Run 2026-10-06_node_discharges_outside.sql first on a database that does not
-- have the column yet. Every node starts unticked, Open Area and Parking
-- included: nothing is a destination until an admin ticks it, so Nearest Exit
-- finds no route in a building until at least one node there is ticked.

ALTER TABLE `nodes`
  CHANGE COLUMN `discharges_outside` `is_emergency_destination` tinyint(1) NOT NULL DEFAULT 0;
