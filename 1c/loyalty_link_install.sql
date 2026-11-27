-- MySQL 5.7. Run in u1677858_1c.
CREATE TABLE IF NOT EXISTS loyalty_link_codes (
 card_id INT UNSIGNED NOT NULL,
 code_hash BINARY(32) NOT NULL,
 owner_guid CHAR(36) NOT NULL,
 expires_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 consumed_at DATETIME DEFAULT NULL,
 PRIMARY KEY (card_id),
 UNIQUE KEY uniq_code_hash (code_hash),
 KEY idx_expires (expires_at),
 CONSTRAINT fk_loyalty_link_card FOREIGN KEY (card_id) REFERENCES loyalty_cards_1c(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS loyalty_link_log (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 card_id INT UNSIGNED DEFAULT NULL,
 user_id INT DEFAULT NULL,
 event_type VARCHAR(32) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (id),
 KEY idx_card_time (card_id,created_at),
 KEY idx_user_time (user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Account-wide limit, shared across sessions; used by future website redemption.
CREATE TABLE IF NOT EXISTS loyalty_link_attempts (
 user_id INT NOT NULL,
 window_started DATETIME NOT NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
