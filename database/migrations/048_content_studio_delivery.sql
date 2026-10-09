-- Scheduled times retain the studio's Asia/Kuala_Lumpur wall-clock format.
-- Delivery attempt/retry timestamps are explicitly UTC, independent of DB TZ.
SET @studio_columns = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'content_posts' AND column_name = 'creative_meta');
SET @studio_sql = IF(@studio_columns = 0,
    'ALTER TABLE content_posts ADD COLUMN creative_meta JSON NULL, ADD COLUMN publish_attempts INT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN publish_attempted_at DATETIME NULL, ADD COLUMN next_retry_at DATETIME NULL, ADD INDEX idx_content_retry (status, next_retry_at)',
    'SELECT 1');
PREPARE studio_stmt FROM @studio_sql;
EXECUTE studio_stmt;
DEALLOCATE PREPARE studio_stmt;
ALTER TABLE content_posts MODIFY publish_status ENUM('published', 'simulated', 'failed', 'publishing', 'uncertain') NULL;

INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('content_publish_time', '18:00'),
    ('content_auto_publish_enabled', '0');
