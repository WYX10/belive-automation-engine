-- Normalize owner properties and let each room define the points awarded when
-- a referred friend confirms a booking for that room. Every ALTER is guarded
-- so a run interrupted after MySQL's implicit DDL commit can safely resume.

CREATE TABLE IF NOT EXISTS properties (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_name  VARCHAR(120) NOT NULL,
    name        VARCHAR(120) NOT NULL,
    location    VARCHAR(100) NOT NULL,
    address     VARCHAR(255) NOT NULL,
    description TEXT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_owner_property (owner_name, name, location),
    INDEX idx_property_owner (owner_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rooms' AND COLUMN_NAME = 'property_id'),
    'SELECT 1',
    'ALTER TABLE rooms ADD COLUMN property_id INT UNSIGNED NULL AFTER id'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rooms' AND COLUMN_NAME = 'referral_reward_points'),
    'SELECT 1',
    'ALTER TABLE rooms ADD COLUMN referral_reward_points INT UNSIGNED NOT NULL DEFAULT 50 AFTER deposit_amount'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO properties (owner_name, name, location, address)
SELECT r.owner_name,
       COALESCE(NULLIF(r.property_name, ''), r.name),
       r.location,
       COALESCE(MAX(NULLIF(r.address, '')), 'Address to be confirmed')
FROM rooms r
WHERE r.owner_name IS NOT NULL AND r.owner_name <> ''
GROUP BY r.owner_name, COALESCE(NULLIF(r.property_name, ''), r.name), r.location
ON DUPLICATE KEY UPDATE address = VALUES(address);

UPDATE rooms r
JOIN properties p
  ON p.owner_name = r.owner_name
 AND p.name = COALESCE(NULLIF(r.property_name, ''), r.name)
 AND p.location = r.location
SET r.property_id = p.id
WHERE r.property_id IS NULL;

SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rooms' AND INDEX_NAME = 'idx_room_property'),
    'SELECT 1',
    'ALTER TABLE rooms ADD INDEX idx_room_property (property_id)'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'rooms' AND CONSTRAINT_NAME = 'fk_room_property'),
    'SELECT 1',
    'ALTER TABLE rooms ADD CONSTRAINT fk_room_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE SET NULL'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'referrals' AND COLUMN_NAME = 'reward_room_id'),
    'SELECT 1',
    'ALTER TABLE referrals ADD COLUMN reward_room_id INT UNSIGNED NULL AFTER referred_lead_id'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'referrals' AND COLUMN_NAME = 'reward_room_name'),
    'SELECT 1',
    'ALTER TABLE referrals ADD COLUMN reward_room_name VARCHAR(120) NULL AFTER reward_room_id'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'referrals' AND COLUMN_NAME = 'reward_property_name'),
    'SELECT 1',
    'ALTER TABLE referrals ADD COLUMN reward_property_name VARCHAR(120) NULL AFTER reward_room_name'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'referrals' AND INDEX_NAME = 'idx_referral_reward_room'),
    'SELECT 1',
    'ALTER TABLE referrals ADD INDEX idx_referral_reward_room (reward_room_id)'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'referrals' AND CONSTRAINT_NAME = 'fk_referral_reward_room'),
    'SELECT 1',
    'ALTER TABLE referrals ADD CONSTRAINT fk_referral_reward_room FOREIGN KEY (reward_room_id) REFERENCES rooms(id) ON DELETE SET NULL'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- This deliberately fails if historical data already attributes one friend to
-- multiple codes: that corruption must be resolved rather than silently lost.
SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'referrals' AND INDEX_NAME = 'uq_referral_referred_lead'),
    'SELECT 1',
    'ALTER TABLE referrals ADD UNIQUE INDEX uq_referral_referred_lead (referred_lead_id)'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
