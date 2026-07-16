-- Phase 9 bonus — Move-In Condition Log: timestamped room-condition photos as
-- dispute-prevention evidence (Sabah doc's landlord-tenant damages problem).

CREATE TABLE IF NOT EXISTS move_in_logs (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id     INT UNSIGNED NOT NULL,
    lead_id     INT UNSIGNED NULL,
    photo_path  VARCHAR(255) NOT NULL,
    caption     VARCHAR(255) NULL,
    taken_at    DATETIME NOT NULL,
    uploaded_by ENUM('admin', 'owner') NOT NULL DEFAULT 'admin',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_room (room_id),
    CONSTRAINT fk_movein_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    CONSTRAINT fk_movein_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
