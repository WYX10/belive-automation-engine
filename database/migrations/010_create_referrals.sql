-- Refer & Earn ledger. One row per share code; referred_lead_id fills when
-- someone arrives via /r/{code}; reward_status flips to credited when that
-- referred lead completes a booking (fixed points, competition scope).

CREATE TABLE IF NOT EXISTS referrals (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    referral_code     VARCHAR(12) NOT NULL UNIQUE,
    referring_lead_id INT UNSIGNED NOT NULL,
    referred_lead_id  INT UNSIGNED NULL,
    reward_points     INT UNSIGNED NOT NULL DEFAULT 0,
    reward_status     ENUM('pending', 'credited') NOT NULL DEFAULT 'pending',
    clicks            INT UNSIGNED NOT NULL DEFAULT 0,
    credited_at       DATETIME NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_referring (referring_lead_id),
    INDEX idx_referred (referred_lead_id),
    CONSTRAINT fk_ref_referring FOREIGN KEY (referring_lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_referred FOREIGN KEY (referred_lead_id) REFERENCES leads(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
