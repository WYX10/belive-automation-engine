-- Phase 9 bonus — Verified Listing (Sabah-derived, software-only).
-- ownership_verified is an ADMIN REVIEW flag on an uploaded document, not a
-- real eKYC API result — stated openly in the demo script.

CREATE TABLE IF NOT EXISTS verified_listings (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id            INT UNSIGNED NOT NULL UNIQUE,
    ownership_doc_path VARCHAR(255) NULL,
    ownership_verified TINYINT(1) NOT NULL DEFAULT 0,
    gps_lat            DECIMAL(10,7) NULL,
    gps_lng            DECIMAL(10,7) NULL,
    gps_matched        TINYINT(1) NOT NULL DEFAULT 0,
    scam_flags         JSON NULL,
    scam_checked_at    DATETIME NULL,
    verified_badge     TINYINT(1) NOT NULL DEFAULT 0,
    verified_at        DATETIME NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_verified_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
