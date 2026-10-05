-- The web app sidebar's Directory: which parts of it visitors see (Saved
-- Directories, campuses, buildings, and each building's room list), edited on the admin
-- Directory page. One row (id 1) holding JSON; no seed row is needed, a
-- missing row means everything is shown. Purely additive.
CREATE TABLE directory_settings (
  id tinyint(3) unsigned NOT NULL,
  config mediumtext NOT NULL,
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
