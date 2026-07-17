-- Upgrade installations that applied the original migration 022 before the
-- immutable referral-attribution safeguards were added. Guards also make this
-- migration safe on clean installs where 022 already created these objects.

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

-- A non-null referred lead belongs to exactly one code. MySQL permits many
-- NULL values in a UNIQUE index, so unused share codes remain available.
SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'referrals' AND INDEX_NAME = 'uq_referral_referred_lead'),
    'SELECT 1',
    'ALTER TABLE referrals ADD UNIQUE INDEX uq_referral_referred_lead (referred_lead_id)'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
