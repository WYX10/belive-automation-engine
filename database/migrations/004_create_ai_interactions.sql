-- Episodic log: every AI skill call writes a row (via EpisodicLogger),
-- including the model's stated reasoning — required so the Learning Log and
-- judges can always ask "why did it do that". message_kind tags outbound
-- messages by content type (price_quote, photos, ...) so drop-off pattern
-- detection can find "died right after a price quote" sequences.

CREATE TABLE IF NOT EXISTS ai_interactions (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lead_id      INT UNSIGNED NULL,
    phase        ENUM('lead_gen', 'conversion', 'content_creation') NOT NULL,
    skill        ENUM('understand', 'decide', 'create', 'automate') NOT NULL,
    model_used   VARCHAR(60) NOT NULL,
    direction    ENUM('inbound', 'outbound', 'internal') NOT NULL DEFAULT 'internal',
    message_in   TEXT NULL,
    message_out  TEXT NULL,
    message_kind VARCHAR(40) NULL,
    intent       VARCHAR(60) NULL,
    entities     JSON NULL,
    reasoning    TEXT NULL,
    memory_used  JSON NULL,
    response_ms  INT UNSIGNED NULL,
    flagged      TINYINT(1) NOT NULL DEFAULT 0,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lead (lead_id),
    INDEX idx_direction_date (direction, created_at),
    INDEX idx_kind (message_kind),
    CONSTRAINT fk_interactions_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
