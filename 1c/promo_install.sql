CREATE TABLE IF NOT EXISTS promo_codes (
 code VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
 status ENUM('active','reserved','used') NOT NULL DEFAULT 'active',
 reservation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 reserved_at DATETIME DEFAULT NULL,
 used_at DATETIME DEFAULT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_reservation (reservation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
