-- Upgrade installations that applied migration 024 before stale admin-decision
-- protection was introduced. Clean installs already receive this in 024.

SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'properties' AND COLUMN_NAME = 'review_version'),
    'DO 1',
    'ALTER TABLE properties ADD COLUMN review_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER review_status'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
