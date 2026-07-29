-- Scheduled publishing for the content studio. Approval no longer has to mean
-- "go out this second": an admin can approve a draft *for a time* — usually one
-- of the slots PostTimingAdvisor suggests from our own engagement data — and
-- cron/publish_scheduled.php publishes it when that time arrives.
--
-- 'scheduled' is a status of its own rather than an approved row with a future
-- date, because cron/publish_retry.php claims every approved row whose publish
-- has not run yet. A scheduled post must be invisible to it until it is due.

ALTER TABLE content_posts
    MODIFY status ENUM('draft','scheduled','approved','rejected','posted') NOT NULL DEFAULT 'draft';

-- MySQL has no "ADD COLUMN IF NOT EXISTS" (only MariaDB does), and the runner
-- records a migration only after its last statement — so a half-finished run
-- replays this file. Same information_schema guard as 029/036/041.
SET @ddl := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_posts' AND COLUMN_NAME = 'scheduled_for'),
    'DO 0',
    'ALTER TABLE content_posts
        ADD COLUMN scheduled_for   DATETIME NULL AFTER posted_at,
        ADD COLUMN scheduled_by    VARCHAR(60) NULL AFTER scheduled_for,
        ADD COLUMN schedule_source ENUM(''suggested'',''custom'',''auto'') NULL AFTER scheduled_by'
);
PREPARE add_schedule_cols FROM @ddl;
EXECUTE add_schedule_cols;
DEALLOCATE PREPARE add_schedule_cols;

-- The due-post query is (status, scheduled_for) every five minutes, forever.
SET @ddl := IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_posts' AND INDEX_NAME = 'idx_scheduled'),
    'DO 0',
    'ALTER TABLE content_posts ADD KEY idx_scheduled (status, scheduled_for)'
);
PREPARE add_schedule_key FROM @ddl;
EXECUTE add_schedule_key;
DEALLOCATE PREPARE add_schedule_key;

-- content_schedule_default — what the preview page pre-selects when an admin
-- approves a draft: 'suggested' (the best slot from our data) or 'now'.
INSERT INTO app_settings (setting_key, setting_value) VALUES
    ('content_schedule_default', 'suggested')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
