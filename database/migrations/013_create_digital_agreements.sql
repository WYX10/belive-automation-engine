-- Phase 9 bonus — Digital Agreement Acknowledgement. A typed-name + checkbox
-- acknowledgement flow with timestamp — NOT a legally binding cryptographic
-- e-signature; stated openly in the demo script.

CREATE TABLE IF NOT EXISTS digital_agreements (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id           INT UNSIGNED NOT NULL,
    room_id           INT UNSIGNED NULL,
    agreement_text    MEDIUMTEXT NOT NULL,
    status            ENUM('draft', 'sent', 'acknowledged') NOT NULL DEFAULT 'draft',
    acknowledged_name VARCHAR(120) NULL,
    acknowledged_at   DATETIME NULL,
    access_code       VARCHAR(16) NOT NULL,
    generated_by_model VARCHAR(60) NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_access_code (access_code),
    CONSTRAINT fk_agreement_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    CONSTRAINT fk_agreement_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
