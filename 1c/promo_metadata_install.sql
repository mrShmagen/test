-- Run once on existing database. Existing codes and reservation states remain unchanged.
ALTER TABLE promo_codes
 ADD COLUMN source_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 ADD COLUMN valid_from DATE NULL,
 ADD COLUMN valid_until DATE NULL,
 ADD COLUMN promo_type ENUM('gift','discount') NULL,
 ADD COLUMN discount_percent DECIMAL(5,2) NULL,
 ADD COLUMN product_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 ADD COLUMN product_name VARCHAR(512) NULL,
 ADD COLUMN gift_quantity DECIMAL(12,3) NULL,
 ADD COLUMN batch_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 ADD COLUMN catalog_enabled TINYINT(1) NOT NULL DEFAULT 1,
 ADD COLUMN catalog_deleted TINYINT(1) NOT NULL DEFAULT 0,
 ADD COLUMN metadata_updated_at DATETIME NULL,
 ADD UNIQUE KEY uq_promo_source_id (source_id),
 ADD KEY ix_promo_batch (batch_id);
