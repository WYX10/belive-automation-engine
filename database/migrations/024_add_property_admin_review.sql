-- Owners submit properties first. Admin approval is required before rooms can
-- be created. Every DDL step is guarded so interrupted migrations can resume.

SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'properties' AND COLUMN_NAME = 'review_status'),
    'DO 1',
    "ALTER TABLE properties ADD COLUMN review_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' AFTER description"
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'properties' AND COLUMN_NAME = 'review_version'),
    'DO 1',
    'ALTER TABLE properties ADD COLUMN review_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER review_status'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'properties' AND COLUMN_NAME = 'review_note'),
    'DO 1',
    'ALTER TABLE properties ADD COLUMN review_note TEXT NULL AFTER review_version'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'properties' AND COLUMN_NAME = 'reviewed_by'),
    'DO 1',
    'ALTER TABLE properties ADD COLUMN reviewed_by VARCHAR(120) NULL AFTER review_note'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'properties' AND COLUMN_NAME = 'reviewed_at'),
    'DO 1',
    'ALTER TABLE properties ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'properties' AND INDEX_NAME = 'idx_property_review_status'),
    'DO 1',
    'ALTER TABLE properties ADD INDEX idx_property_review_status (review_status, created_at)'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Properties that already had rooms before this workflow are grandfathered so
-- existing live inventory is not disabled retroactively.
UPDATE properties p
SET p.review_status = 'approved',
    p.reviewed_by = COALESCE(p.reviewed_by, 'migration-024'),
    p.reviewed_at = COALESCE(p.reviewed_at, NOW())
WHERE p.review_status = 'pending'
  AND EXISTS (SELECT 1 FROM rooms r WHERE r.property_id = p.id);
