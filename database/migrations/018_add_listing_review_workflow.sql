-- Dedicated admin workflow for ownership and GPS evidence reviews.
-- The boolean fields remain the badge inputs; these fields add the missing
-- queue state, reviewer identity, timestamps and owner-facing feedback.

ALTER TABLE verified_listings
    ADD COLUMN ownership_review_status ENUM('not_submitted', 'pending', 'approved', 'rejected') NOT NULL DEFAULT 'not_submitted' AFTER ownership_verified,
    ADD COLUMN ownership_review_note VARCHAR(500) NULL AFTER ownership_review_status,
    ADD COLUMN ownership_reviewed_by VARCHAR(120) NULL AFTER ownership_review_note,
    ADD COLUMN ownership_reviewed_at DATETIME NULL AFTER ownership_reviewed_by,
    ADD COLUMN gps_review_status ENUM('not_submitted', 'pending', 'approved', 'rejected') NOT NULL DEFAULT 'not_submitted' AFTER gps_matched,
    ADD COLUMN gps_review_note VARCHAR(500) NULL AFTER gps_review_status,
    ADD COLUMN gps_reviewed_by VARCHAR(120) NULL AFTER gps_review_note,
    ADD COLUMN gps_reviewed_at DATETIME NULL AFTER gps_reviewed_by,
    ADD INDEX idx_verified_review_queue (ownership_review_status, gps_review_status);

UPDATE verified_listings
SET ownership_review_status = CASE
        WHEN ownership_verified = 1 THEN 'approved'
        WHEN ownership_doc_path IS NOT NULL THEN 'pending'
        ELSE 'not_submitted'
    END,
    gps_review_status = CASE
        WHEN gps_matched = 1 THEN 'approved'
        WHEN gps_lat IS NOT NULL AND gps_lng IS NOT NULL THEN 'pending'
        ELSE 'not_submitted'
    END;
