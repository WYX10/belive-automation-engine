-- AI-generated social post drafts (CreateSkill caption mode). Publishing is
-- explicitly semi-automated where a platform's publish API is out of scope —
-- admin approves/marks posted from Admin → Content.

CREATE TABLE IF NOT EXISTS content_posts (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    platform           ENUM('facebook', 'instagram', 'tiktok') NOT NULL,
    room_id            INT UNSIGNED NULL,
    caption            TEXT NOT NULL,
    status             ENUM('draft', 'approved', 'posted') NOT NULL DEFAULT 'draft',
    generated_by_model VARCHAR(60) NOT NULL,
    posted_at          DATETIME NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_platform (platform)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
