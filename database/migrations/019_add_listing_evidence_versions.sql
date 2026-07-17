-- Monotonic versions let admin decisions fail safely when owner evidence was
-- replaced after the review page was opened.

ALTER TABLE verified_listings
    ADD COLUMN ownership_evidence_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER ownership_doc_path,
    ADD COLUMN gps_evidence_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER gps_lng;

UPDATE verified_listings
SET ownership_evidence_version = IF(ownership_doc_path IS NULL, 0, 1),
    gps_evidence_version = IF(gps_lat IS NULL OR gps_lng IS NULL, 0, 1);
