-- Learning Mechanism + Memory (self-learning steps 2–3): distilled, reusable
-- rules produced by LearningEngine. rule_type supports factual corrections AND
-- sequencing/strategy lessons (Setapak = 'sequencing'). Rules are soft-
-- deactivated (active=0) when confidence collapses — kept for audit trail.

CREATE TABLE IF NOT EXISTS ai_learned_memory (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    context_tag        VARCHAR(60) NOT NULL DEFAULT 'general',
    rule_type          ENUM('fact', 'sequencing', 'strategy', 'tone') NOT NULL DEFAULT 'fact',
    learned_rule       TEXT NOT NULL,
    source_feedback_id INT UNSIGNED NULL,
    confidence_score   DECIMAL(4,3) NOT NULL DEFAULT 0.600,
    times_reinforced   INT UNSIGNED NOT NULL DEFAULT 0,
    times_contradicted INT UNSIGNED NOT NULL DEFAULT 0,
    active             TINYINT(1) NOT NULL DEFAULT 1,
    last_used_at       DATETIME NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_context_active (context_tag, active),
    INDEX idx_confidence (confidence_score),
    CONSTRAINT fk_memory_feedback FOREIGN KEY (source_feedback_id) REFERENCES ai_feedback(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
