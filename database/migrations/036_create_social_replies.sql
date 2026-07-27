-- Social auto-reply: FB/IG comment or DM in, WhatsApp hand-off out.
--
-- One row per social event Eve has answered. It does three jobs:
--
--  1. DEDUP. Meta re-delivers a webhook whenever the previous attempt did not
--     answer fast enough, and Meta allows exactly ONE private reply per
--     comment -- a second attempt is an API error. The unique key on
--     (platform, object_id) means the first delivery claims the event and
--     every redelivery is dropped, the same trick processed_webhook_messages
--     plays for WhatsApp.
--
--  2. ATTRIBUTION. The wa.me link we send carries a short token, prefilled
--     into the customer's first WhatsApp message. When that message arrives,
--     the token is redeemed here and the social lead is merged into the real
--     WhatsApp lead -- so "this tenant came from an Instagram comment" is a
--     recorded fact, not a guess. A token is single-use (claimed_at).
--
--  3. AUDIT. Which reply went out, publicly and privately, and whether it was
--     really delivered or only simulated (no meta_graph credential).

CREATE TABLE IF NOT EXISTS social_replies (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    platform       ENUM('facebook', 'instagram') NOT NULL,
    event_type     ENUM('comment', 'direct_message') NOT NULL,
    -- Comment id, or message id (mid) for a DM. Unique per platform.
    object_id      VARCHAR(160) NOT NULL,
    sender_id      VARCHAR(80) NOT NULL,
    sender_name    VARCHAR(120) NULL,
    lead_id        INT UNSIGNED NULL,
    ref_token      VARCHAR(16) NULL,
    public_reply   ENUM('pending', 'skipped', 'sent', 'simulated', 'failed') NOT NULL DEFAULT 'pending',
    private_reply  ENUM('pending', 'skipped', 'sent', 'simulated', 'failed') NOT NULL DEFAULT 'pending',
    error          VARCHAR(500) NULL,
    -- The WhatsApp lead that redeemed the token, and when.
    claimed_by_lead_id INT UNSIGNED NULL,
    claimed_at     DATETIME NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_social_event (platform, object_id),
    UNIQUE KEY uq_social_token (ref_token),
    KEY idx_social_lead (lead_id),
    KEY idx_social_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A social lead that has been folded into a WhatsApp lead keeps its row (the
-- history hangs off it) but points at its successor, so the leads list can
-- hide it instead of showing the same person twice.
--
-- Guarded rather than a bare ALTER: the runner records a migration only after
-- its LAST statement, so a run interrupted part-way (a killed deploy, a closed
-- SSH console) replays the whole file next time. MySQL has no
-- "ADD COLUMN IF NOT EXISTS" -- only MariaDB does -- so the check goes through
-- information_schema, which both understand.
SET @has_merge_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'merged_into_lead_id'
);
SET @ddl := IF(
    @has_merge_col > 0,
    'DO 0',
    'ALTER TABLE leads ADD COLUMN merged_into_lead_id INT UNSIGNED NULL AFTER referral_code_used'
);
PREPARE add_merge_col FROM @ddl;
EXECUTE add_merge_col;
DEALLOCATE PREPARE add_merge_col;

SET @has_merge_key := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND INDEX_NAME = 'idx_lead_merged'
);
SET @ddl := IF(
    @has_merge_key > 0,
    'DO 0',
    'ALTER TABLE leads ADD KEY idx_lead_merged (merged_into_lead_id)'
);
PREPARE add_merge_key FROM @ddl;
EXECUTE add_merge_key;
DEALLOCATE PREPARE add_merge_key;

-- Reply copy is admin-editable (Admin -> Social auto-reply), not hardcoded:
-- the wording is marketing's call, and Meta's policies on what you may send
-- change faster than a deploy cycle.
--   {name}     first name of the commenter, or "there"
--   {link}     the wa.me link, token already embedded
--   {platform} "Instagram" / "Facebook"
INSERT INTO app_settings (setting_key, setting_value) VALUES
    ('social_autoreply_enabled', '1'),
    ('social_reply_scope', 'enquiry'),
    ('social_wa_number', ''),
    ('social_wa_prefill', 'Hi beLive! I saw your {platform} post [{token}]'),
    ('social_comment_public_reply', 'Hi {name}! 🏠 Just sent you a DM with the details — check your inbox 💬'),
    ('social_comment_dm', 'Hi {name}! Thanks for your comment 🏠\n\nChat with Eve on WhatsApp for live availability, real prices and instant viewing booking:\n{link}\n\nSee you there! — beLive'),
    ('social_dm_reply', 'Hi {name}! Thanks for messaging beLive 🏠\n\nEve handles everything on WhatsApp — live room availability, honest prices and viewing slots you can book in one message:\n{link}\n\nTalk there? — beLive')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
