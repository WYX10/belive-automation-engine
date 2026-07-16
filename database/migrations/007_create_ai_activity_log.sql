-- Audit trail: every AI action, with which model handled it, so the admin
-- panel can always answer "which model made this decision" (non-negotiable
-- build rule).

CREATE TABLE IF NOT EXISTS ai_activity_log (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action     VARCHAR(80) NOT NULL,
    phase      VARCHAR(30) NULL,
    model_used VARCHAR(60) NULL,
    lead_id    INT UNSIGNED NULL,
    detail     TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_action (action),
    INDEX idx_created (created_at),
    INDEX idx_lead (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
