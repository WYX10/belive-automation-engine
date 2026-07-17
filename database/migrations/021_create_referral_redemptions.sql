-- Tenant referral points can be reserved for a real rent-credit request.
-- The request remains auditable through review/application instead of being
-- presented as an automatically applied payment-system credit.

CREATE TABLE IF NOT EXISTS referral_redemptions (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id            INT UNSIGNED NOT NULL,
    reward_type        ENUM('rent_credit') NOT NULL DEFAULT 'rent_credit',
    points_spent       INT UNSIGNED NOT NULL,
    rent_credit_amount DECIMAL(8,2) NOT NULL,
    status             ENUM('requested', 'approved', 'applied', 'rejected') NOT NULL DEFAULT 'requested',
    requested_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at        DATETIME NULL,
    applied_at         DATETIME NULL,
    review_note        VARCHAR(255) NULL,
    INDEX idx_redemption_lead (lead_id, requested_at),
    INDEX idx_redemption_status (status),
    CONSTRAINT fk_redemption_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
