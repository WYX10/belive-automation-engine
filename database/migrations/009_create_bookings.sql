-- Rooms + bookings. The rooms table lives here (same migration) because
-- bookings, availability checks, photo-before-price sequencing, and Phase 9's
-- fair-pricing comparisons all need real room inventory — file numbering kept
-- exactly as the agreed structure.

CREATE TABLE IF NOT EXISTS rooms (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    area        VARCHAR(100) NOT NULL,
    room_type   ENUM('small', 'medium', 'master', 'studio') NOT NULL,
    price       DECIMAL(8,2) NOT NULL,
    photos      JSON NULL,
    features    JSON NULL,
    available   TINYINT(1) NOT NULL DEFAULT 1,
    owner_name  VARCHAR(120) NULL,
    address     VARCHAR(255) NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_area_type (area, room_type),
    INDEX idx_available (available)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id           INT UNSIGNED NOT NULL,
    room_id           INT UNSIGNED NULL,
    viewing_datetime  DATETIME NOT NULL,
    status            ENUM('pending', 'confirmed', 'cancelled', 'completed') NOT NULL DEFAULT 'pending',
    confirmation_sent TINYINT(1) NOT NULL DEFAULT 0,
    notes             TEXT NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_viewing (viewing_datetime),
    INDEX idx_status (status),
    CONSTRAINT fk_bookings_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    CONSTRAINT fk_bookings_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
