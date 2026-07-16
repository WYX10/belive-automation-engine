-- One table for all six proposal lead channels (source_channel discriminates).
-- wa_phone is the cross-channel identity key behind the proposal's "Memory"
-- claim: a returning number maps to the same lead, so Eve can recall the name
-- and prior enquiry. AI assessment fields feed the dashboard's lead detail
-- panel (closing probability %, detected signals, recommendation line).

CREATE TABLE IF NOT EXISTS leads (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wa_phone            VARCHAR(20) NOT NULL,
    name                VARCHAR(120) NULL,
    source_channel      ENUM('whatsapp', 'social', 'website', 'listing_portal', 'referral', 'tiktok') NOT NULL,
    status              ENUM('new', 'qualified', 'converted') NOT NULL DEFAULT 'new',
    location            VARCHAR(100) NULL,
    budget              VARCHAR(50) NULL,
    move_in_date        VARCHAR(50) NULL,
    room_type           VARCHAR(50) NULL,
    tenant_profile      VARCHAR(50) NULL,
    closing_probability TINYINT UNSIGNED NULL,
    lead_signals        JSON NULL,
    ai_recommendation   TEXT NULL,
    notes               TEXT NULL,
    referral_code_used  VARCHAR(12) NULL,
    last_contact_at     DATETIME NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wa_phone (wa_phone),
    INDEX idx_status (status),
    INDEX idx_source (source_channel),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
