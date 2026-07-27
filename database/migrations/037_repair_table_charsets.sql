-- Repair tables that were created without an explicit charset.
--
-- Most CREATE TABLEs in this project end with
-- "DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci". Four did not, and so
-- inherited whatever the DATABASE default happened to be. That is invisible
-- in development -- migrate.php creates the database as utf8mb4 -- and breaks
-- on a server where the database was provisioned for us with a narrower
-- default: any 4-byte character (every emoji) is rejected with
-- "1366 Incorrect string value".
--
-- app_settings is repaired in 036, which needs it before its own INSERT runs.
-- The staff tables are repaired here: a roster holds human names, and a name
-- is exactly the kind of value nobody should have to keep inside the BMP.
--
-- CONVERT TO CHARACTER SET rebuilds the table, so each one is skipped when it
-- is already utf8mb4 and this migration is cheap to re-run.

SET @staff_needs_utf8mb4 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff'
      AND TABLE_COLLATION NOT LIKE 'utf8mb4%'
);
SET @ddl := IF(
    @staff_needs_utf8mb4 > 0,
    'ALTER TABLE staff CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    'DO 0'
);
PREPARE fix_staff FROM @ddl;
EXECUTE fix_staff;
DEALLOCATE PREPARE fix_staff;

SET @shifts_needs_utf8mb4 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_shifts'
      AND TABLE_COLLATION NOT LIKE 'utf8mb4%'
);
SET @ddl := IF(
    @shifts_needs_utf8mb4 > 0,
    'ALTER TABLE staff_shifts CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    'DO 0'
);
PREPARE fix_shifts FROM @ddl;
EXECUTE fix_shifts;
DEALLOCATE PREPARE fix_shifts;

SET @timeoff_needs_utf8mb4 := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_time_off'
      AND TABLE_COLLATION NOT LIKE 'utf8mb4%'
);
SET @ddl := IF(
    @timeoff_needs_utf8mb4 > 0,
    'ALTER TABLE staff_time_off CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    'DO 0'
);
PREPARE fix_timeoff FROM @ddl;
EXECUTE fix_timeoff;
DEALLOCATE PREPARE fix_timeoff;
