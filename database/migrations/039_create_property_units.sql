-- The missing middle of the portfolio. A property is the development
-- ("121 Residence"); BeLive rents whole units inside it and sublets the rooms.
-- Until now rooms hung straight off the property, so "which house is this
-- master room in" had no answer and admin saw 178 rooms in one flat list.
--
-- property_units is that house level: Property -> House -> Room. Every ALTER
-- is guarded so a run interrupted after MySQL's implicit DDL commit resumes
-- cleanly, and the backfill is written to be safe to re-run.

CREATE TABLE IF NOT EXISTS property_units (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id INT UNSIGNED NOT NULL,
    name        VARCHAR(120) NOT NULL,
    notes       VARCHAR(255) NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_property_unit (property_id, name),
    INDEX idx_unit_property (property_id),
    CONSTRAINT fk_unit_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rooms' AND COLUMN_NAME = 'unit_id'),
    'DO 1',
    'ALTER TABLE rooms ADD COLUMN unit_id INT UNSIGNED NULL AFTER property_id'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rooms' AND INDEX_NAME = 'idx_room_unit'),
    'DO 1',
    'ALTER TABLE rooms ADD INDEX idx_room_unit (unit_id)'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- SET NULL rather than CASCADE: deleting a house must never silently delete
-- the rooms (and their bookings and agreements) that were inside it.
SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'rooms' AND CONSTRAINT_NAME = 'fk_room_unit'),
    'DO 1',
    'ALTER TABLE rooms ADD CONSTRAINT fk_room_unit FOREIGN KEY (unit_id) REFERENCES property_units(id) ON DELETE SET NULL'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill: every property gets one house so the drill-down is never a dead
-- end, and existing rooms land in it. Admin splits them into the real units
-- afterwards from the room page. The unique key makes the INSERT a no-op on a
-- second run, and the UPDATE only touches rooms not yet placed in a house.
INSERT INTO property_units (property_id, name, notes)
SELECT p.id, 'Main house', 'Created automatically when the house level was added — rename it or split the rooms into the real units.'
FROM properties p
ON DUPLICATE KEY UPDATE property_units.notes = property_units.notes;

UPDATE rooms r
JOIN property_units u ON u.property_id = r.property_id AND u.name = 'Main house'
SET r.unit_id = u.id
WHERE r.unit_id IS NULL AND r.property_id IS NOT NULL;
