-- Engagement on the posts we published: viewers, likes, comments, shares.
--
-- One row per post per DAY, not per refresh. A platform counter is cumulative
-- ("this post has 412 views"), so storing every poll would only repeat the same
-- number; storing the last poll of each day gives a trend line for free and
-- keeps the table bounded at (published posts x days).
--
-- Every metric column is NULLABLE on purpose, and NULL is not zero:
--
--   NULL  the platform did not give us this number (metric not supported for
--         this media type, missing permission, TikTok has no read API at all)
--   0     the platform gave us the number and it is genuinely zero
--
-- The admin panel prints "—" for NULL and "0" for zero, so a gap in Meta's
-- insights is never dressed up as a post nobody liked. `source` says where a
-- row came from and `note` says, in one sentence, why anything is missing.

CREATE TABLE IF NOT EXISTS content_post_metrics (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_post_id INT UNSIGNED NOT NULL,
    platform        ENUM('facebook', 'instagram', 'tiktok') NOT NULL,
    -- The day this snapshot belongs to (app timezone) + the exact poll time.
    captured_on     DATE     NOT NULL,
    captured_at     DATETIME NOT NULL,
    -- 'platform'    = numbers read back from the platform's own API
    -- 'unavailable' = nothing to read (dry-run post, no credential, no read API)
    source          ENUM('platform', 'unavailable') NOT NULL DEFAULT 'platform',
    views           INT UNSIGNED NULL,
    likes           INT UNSIGNED NULL,
    comments        INT UNSIGNED NULL,
    shares          INT UNSIGNED NULL,
    note            VARCHAR(255) NULL,
    -- The API response as received, so a number on screen can always be traced
    -- back to what the platform actually said.
    raw             TEXT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Re-polling the same post on the same day updates its row (upsert).
    UNIQUE KEY uq_post_day (content_post_id, captured_on),
    KEY idx_metrics_captured (captured_at),
    KEY idx_metrics_platform (platform, captured_on),
    CONSTRAINT fk_metrics_post FOREIGN KEY (content_post_id)
        REFERENCES content_posts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- How often the refresh cron may re-poll a single post. Meta rate-limits per
-- app, and a two-week-old post's numbers barely move, so the collector spends
-- its budget on the posts that are still moving (see EngagementCollector).
INSERT INTO app_settings (setting_key, setting_value) VALUES
    ('engagement_refresh_minutes', '180')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
