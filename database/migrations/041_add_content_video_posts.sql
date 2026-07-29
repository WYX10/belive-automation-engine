-- Promo videos in the content studio. A draft is now either a photo post or a
-- rendered vertical reel: media_kind says which, video_url holds the rendered
-- mp4 (site-local, same convention as image_url — image_url stays populated as
-- the poster frame) and video_script keeps the AI's shot list as JSON so the
-- preview can show exactly what each second of the video says.

SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_posts' AND COLUMN_NAME = 'media_kind'),
    'DO 1',
    'ALTER TABLE content_posts
        ADD COLUMN media_kind   ENUM(''image'',''video'') NOT NULL DEFAULT ''image'' AFTER platform,
        ADD COLUMN video_url    VARCHAR(255) NULL AFTER image_url,
        ADD COLUMN video_script TEXT         NULL AFTER video_url'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
