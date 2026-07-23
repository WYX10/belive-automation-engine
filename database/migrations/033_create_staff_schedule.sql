-- Staff scheduling: who is on duty to run a viewing, and when. Eve reads
-- these tables before proposing a slot, so a proposed time is only offered
-- when a real human is rostered to take it (video call or face-to-face).
--
-- staff_shifts is the recurring weekly roster (weekday 0 = Sunday … 6 = Saturday,
-- matching PHP's date('w')). staff_time_off punches one-off holes in it.

CREATE TABLE IF NOT EXISTS staff (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    role VARCHAR(60) NOT NULL DEFAULT 'Viewing agent',
    wa_phone VARCHAR(20) NULL,
    email VARCHAR(120) NULL,
    handles_video TINYINT(1) NOT NULL DEFAULT 1,
    handles_in_person TINYINT(1) NOT NULL DEFAULT 1,
    max_daily_viewings TINYINT UNSIGNED NOT NULL DEFAULT 8,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_staff_active (active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS staff_shifts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id INT UNSIGNED NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL,
    starts_at TIME NOT NULL,
    ends_at TIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_staff_shift (staff_id, weekday, starts_at),
    KEY idx_shift_weekday (weekday),
    CONSTRAINT fk_shift_staff FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS staff_time_off (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id INT UNSIGNED NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    reason VARCHAR(120) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_time_off_window (staff_id, starts_at, ends_at),
    CONSTRAINT fk_time_off_staff FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Which agent is running this viewing. NULL = no roster configured yet, or
-- the booking predates staff scheduling.

SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'staff_id'),
    'DO 1',
    'ALTER TABLE bookings ADD COLUMN staff_id INT UNSIGNED NULL AFTER viewing_mode, ADD KEY idx_booking_staff (staff_id, viewing_datetime)'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
