-- Этап 11. Выполнить один раз, если предыдущая расширенная миграция НЕ выполнялась.
-- Существующие коды, резервы и история использования сохраняются.
ALTER TABLE promo_codes
 MODIFY COLUMN status ENUM('active','reserved','used','disabled') NOT NULL DEFAULT 'active',
 ADD COLUMN user_id INT(11) NULL DEFAULT NULL,
 ADD COLUMN valid_from DATE NULL,
 ADD COLUMN valid_until DATE NULL,
 ADD COLUMN promo_type ENUM('gift','discount') NULL,
 ADD COLUMN discount_percent DECIMAL(5,2) NULL,
 ADD COLUMN product_sku VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
 ADD COLUMN gift_quantity DECIMAL(10,3) NULL,
 ADD INDEX ix_promo_user (user_id);
