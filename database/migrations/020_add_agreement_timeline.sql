-- Structured agreement dates power the tenant countdown. Legacy agreements
-- remain NULL because their issue date and the lead's current preference are
-- not reliable contract terms. All newly generated agreements write the three
-- fields explicitly.

ALTER TABLE digital_agreements
    ADD COLUMN tenure ENUM('monthly', '6_month', '12_month') NULL AFTER room_id,
    ADD COLUMN starts_on DATE NULL AFTER tenure,
    ADD COLUMN ends_on DATE NULL AFTER starts_on,
    ADD KEY idx_agreement_ends_on (ends_on);
