-- Viewing mode: every booking records HOW the customer wants to view — an
-- online video call or a face-to-face visit at the property. NULL means Eve
-- has proposed an exact slot and is still waiting for the customer to pick.

SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'viewing_mode'),
    'DO 1',
    "ALTER TABLE bookings ADD COLUMN viewing_mode ENUM('video_call', 'in_person') NULL AFTER viewing_datetime"
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
