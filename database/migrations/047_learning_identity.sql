-- Unique identities protect against concurrent duplicate feedback and lessons.
ALTER TABLE ai_feedback ADD COLUMN feedback_hash CHAR(64) NULL;
ALTER TABLE ai_feedback ADD COLUMN memory_id INT UNSIGNED NULL;
ALTER TABLE ai_feedback MODIFY feedback_source ENUM('admin_flag', 'customer_correction', 'implicit_repeat', 'implicit_dropoff', 'pattern_detection', 'system_detection') NOT NULL;
CREATE UNIQUE INDEX uq_feedback_hash ON ai_feedback(feedback_hash);

ALTER TABLE ai_learned_memory ADD COLUMN lesson_hash CHAR(64) NULL;
ALTER TABLE ai_learned_memory ADD COLUMN lesson_key VARCHAR(160) NULL;
CREATE UNIQUE INDEX uq_lesson_hash ON ai_learned_memory(lesson_hash);
