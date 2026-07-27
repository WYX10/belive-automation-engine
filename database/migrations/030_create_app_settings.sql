-- Small key/value store for admin-tunable settings. First users are the
-- content studio's automation controls: how many drafts the daily cron may
-- create per run, which platforms it covers, and the standing content brief
-- ("what should Eve write about?") that is appended to the caption prompt.

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    updated_by VARCHAR(60) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value) VALUES
    ('content_auto_max', '3'),
    ('content_auto_platforms', 'facebook,instagram,tiktok'),
    ('content_brief', '')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
