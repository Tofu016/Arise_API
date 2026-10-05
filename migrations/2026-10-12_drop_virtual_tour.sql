-- Removes the Virtual Campus Tour entirely: the /tour page, its two admin
-- editors (Tour Stops, Campus Tour Navigation Editor) and the whole
-- TourStops_API/TourSections_API/TourUploads_API backend are gone, so these
-- tables have nothing left reading or writing them.
--
-- Order matters: tour_stop_neighbors has foreign keys into tour_stops, and
-- tour_stops one into tour_sections. Nothing outside this set referenced any
-- of them, so no other table needs touching.
--
-- Photo files are NOT deleted by this: anything still under
-- `uploads/tourpanorama/` or `uploads/tourcover/` is simply unreferenced
-- afterwards, the same way `uploads/tourmarker/` was left when Virtual Tour
-- markers were removed. Delete those folders by hand once you are sure.
--
-- A fresh database does not need this: load schema.sql instead.
--   mysql -u root arise_web < migrations/2026-10-12_drop_virtual_tour.sql

DROP TABLE IF EXISTS `tour_stop_neighbors`;
DROP TABLE IF EXISTS `tour_stops`;
DROP TABLE IF EXISTS `tour_sections`;
