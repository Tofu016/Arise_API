-- Adds the hit log behind the rate limits on login, feedback submit and
-- analytics track (see application/libraries/Rate_limit.php). A fresh
-- database does not need this: load schema.sql instead. Safe to run once on
-- an existing database; it only creates a table.
--
--   mysql -u root arise_web < migrations/2026-10-04_rate_limit_hits.sql

CREATE TABLE IF NOT EXISTS `rate_limit_hits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `bucket` varchar(32) NOT NULL,
  `subject` char(64) NOT NULL,
  `hit_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rate_limit_lookup` (`bucket`,`subject`,`hit_at`),
  KEY `idx_rate_limit_hit_at` (`hit_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
