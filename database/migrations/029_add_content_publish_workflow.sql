-- Content publish workflow: the studio graduates from "approve then paste
-- manually" to approve -> automatic platform publish (FB/IG/TikTok) with a
-- dry-run fallback. Adds review audit + optimistic lock (mirrors the property
-- review columns from 024/025) and publish-outcome tracking to content_posts,
-- and registers TikTok as a credential service for the Content Posting API.

ALTER TABLE content_posts
    MODIFY status ENUM('draft','approved','rejected','posted') NOT NULL DEFAULT 'draft';

SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_posts' AND COLUMN_NAME = 'review_version'),
    'DO 1',
    'ALTER TABLE content_posts
        ADD COLUMN review_version   INT UNSIGNED NOT NULL DEFAULT 1 AFTER status,
        ADD COLUMN reviewed_by      VARCHAR(60)  NULL AFTER review_version,
        ADD COLUMN reviewed_at      DATETIME     NULL AFTER reviewed_by,
        ADD COLUMN review_note      VARCHAR(500) NULL AFTER reviewed_at,
        ADD COLUMN publish_status   ENUM(''published'',''simulated'',''failed'') NULL AFTER review_note,
        ADD COLUMN publish_error    VARCHAR(500) NULL AFTER publish_status,
        ADD COLUMN external_post_id VARCHAR(120) NULL AFTER publish_error,
        ADD COLUMN image_url        VARCHAR(255) NULL AFTER external_post_id,
        ADD COLUMN generated_via    ENUM(''manual'',''cron'') NOT NULL DEFAULT ''manual'' AFTER generated_by_model'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE api_credentials
    MODIFY service ENUM('whatsapp','anthropic','gemini','meta_graph','openai','openrouter','tiktok') NOT NULL;
