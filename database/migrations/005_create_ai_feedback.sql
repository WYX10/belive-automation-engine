-- Feedback Collection (self-learning step 1). error_type includes the factual
-- kinds AND the strategy kinds (poor_sequencing, low_engagement) — the
-- proposal's Setapak drop-off example is poor_sequencing, detected from
-- patterns, not an explicit correction. processed flips when LearningEngine
-- has distilled the feedback into an ai_learned_memory rule.

CREATE TABLE IF NOT EXISTS ai_feedback (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    interaction_id  INT UNSIGNED NULL,
    lead_id         INT UNSIGNED NULL,
    feedback_source ENUM('admin_flag', 'customer_correction', 'implicit_repeat',
                         'implicit_dropoff', 'pattern_detection') NOT NULL,
    error_type      ENUM('wrong_price', 'wrong_availability', 'wrong_tone', 'missed_intent',
                         'poor_sequencing', 'low_engagement') NOT NULL,
    comment         TEXT NULL,
    processed       TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_processed (processed),
    INDEX idx_interaction (interaction_id),
    CONSTRAINT fk_feedback_interaction FOREIGN KEY (interaction_id) REFERENCES ai_interactions(id) ON DELETE SET NULL,
    CONSTRAINT fk_feedback_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
