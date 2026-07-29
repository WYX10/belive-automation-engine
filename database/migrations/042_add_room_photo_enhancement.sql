-- AI photo touch-up for owner-uploaded room photos. Owners shoot their rooms
-- on a phone in bad light; admin can now have a vision model read the photo,
-- decide the correction and have it applied — exposure, white balance, tilt
-- and sharpness only, never new content (see App\Properties\RoomPhotoEnhancer).
--
-- image_path always points at what the public sees. original_path being set is
-- what "this photo is currently the touched-up version" means, and it holds the
-- untouched upload so Revert is always one click and never lossy — the original
-- file is never overwritten, only unreferenced.

SET @ddl = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'room_images' AND COLUMN_NAME = 'original_path'),
    'DO 1',
    'ALTER TABLE room_images
        ADD COLUMN original_path     VARCHAR(255) NULL AFTER image_path,
        ADD COLUMN enhanced_at       TIMESTAMP    NULL AFTER original_path,
        ADD COLUMN enhanced_by_model VARCHAR(80)  NULL AFTER enhanced_at,
        ADD COLUMN enhance_note      VARCHAR(500) NULL AFTER enhanced_by_model,
        ADD COLUMN enhance_recipe    TEXT         NULL AFTER enhance_note'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
