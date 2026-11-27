-- Run once during coordinated deployment, with card linking paused.
-- Existing links are preserved; only pending codes are invalidated.
CREATE TABLE IF NOT EXISTS loyalty_link_global_limit (
 id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
 window_started DATETIME NOT NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;
DELETE FROM loyalty_link_codes WHERE consumed_at IS NULL;
