-- Agreement signing workflow — the round trip a real tenancy agreement makes.
--
-- The tenant confirms the room, then admin generates the AI draft, the OWNER
-- (landlord) completes their particulars and signs, admin checks the returned
-- document, and only then does the tenant review and sign. Each hand-off is a
-- status, and every hand-off is written to agreement_events.
--
--   draft         AI draft sitting with admin (editable, regenerable)
--   owner_review  with the landlord: particulars, bank details, signature
--   admin_review  landlord signed and returned it: admin's final check
--   tenant_review with the tenant: review and sign
--   completed     both parties signed
--   cancelled     voided by admin (wrong room, superseded, tenant withdrew)
--
-- Immutability: the document is rendered from agreement_text + the particulars
-- below rather than stored as one frozen blob, so the columns that feed it are
-- LOCKED by status. The owner can only write particulars while owner_review;
-- admin can only edit the draft while draft. Sending it back to the owner
-- clears their signature and bumps stage_version, so nobody ever stays signed
-- against a document that changed underneath them.
--
-- NRIC and bank account number are encrypted at rest (App\Core\Encryption,
-- same AES-256-GCM used for API credentials). The last four digits are stored
-- in the clear so admin lists can identify an account without decrypting it.

-- Step 1 — widen the enum so the legacy values can be translated in place.
ALTER TABLE digital_agreements
    MODIFY COLUMN status ENUM(
        'draft', 'sent', 'acknowledged',
        'owner_review', 'admin_review', 'tenant_review', 'completed', 'cancelled'
    ) NOT NULL DEFAULT 'draft';

-- Step 2 — translate. A pre-workflow agreement was issued straight to the
-- tenant with no landlord particulars on it, so an unsigned one goes back to
-- 'draft': it has to travel through the owner before anyone can sign it now.
-- Ones the tenant already acknowledged are history and stay signed.
UPDATE digital_agreements SET status = 'draft' WHERE status = 'sent';
UPDATE digital_agreements SET status = 'completed' WHERE status = 'acknowledged';

-- Step 3 — drop the legacy values so an invalid stage can never be written.
ALTER TABLE digital_agreements
    MODIFY COLUMN status ENUM(
        'draft', 'owner_review', 'admin_review', 'tenant_review', 'completed', 'cancelled'
    ) NOT NULL DEFAULT 'draft';

-- Rent and deposit are snapshotted at generation, never re-read from live
-- pricing: a tariff change next month must not silently rewrite a document
-- somebody already signed (same rule as referral rewards and electric bills).
ALTER TABLE digital_agreements
    ADD COLUMN stage_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status,
    ADD COLUMN monthly_rent_rm DECIMAL(10,2) NULL AFTER ends_on,
    ADD COLUMN deposit_rm DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER monthly_rent_rm,
    ADD COLUMN owner_name VARCHAR(120) NULL AFTER stage_version,
    ADD COLUMN owner_full_name VARCHAR(120) NULL AFTER owner_name,
    ADD COLUMN owner_ic_enc TEXT NULL AFTER owner_full_name,
    ADD COLUMN owner_ic_last4 VARCHAR(4) NULL AFTER owner_ic_enc,
    ADD COLUMN owner_email VARCHAR(120) NULL AFTER owner_ic_last4,
    ADD COLUMN owner_phone VARCHAR(40) NULL AFTER owner_email,
    ADD COLUMN owner_address VARCHAR(255) NULL AFTER owner_phone,
    ADD COLUMN owner_bank_name VARCHAR(80) NULL AFTER owner_address,
    ADD COLUMN owner_bank_holder VARCHAR(120) NULL AFTER owner_bank_name,
    ADD COLUMN owner_bank_account_enc TEXT NULL AFTER owner_bank_holder,
    ADD COLUMN owner_bank_account_last4 VARCHAR(4) NULL AFTER owner_bank_account_enc,
    ADD COLUMN owner_extra_terms TEXT NULL AFTER owner_bank_account_last4,
    ADD COLUMN owner_signed_name VARCHAR(120) NULL AFTER owner_extra_terms,
    ADD COLUMN owner_signed_at DATETIME NULL AFTER owner_signed_name,
    ADD COLUMN sent_to_owner_at DATETIME NULL AFTER owner_signed_at,
    ADD COLUMN admin_review_note VARCHAR(500) NULL AFTER sent_to_owner_at,
    ADD COLUMN admin_reviewed_by VARCHAR(120) NULL AFTER admin_review_note,
    ADD COLUMN admin_reviewed_at DATETIME NULL AFTER admin_reviewed_by,
    ADD COLUMN sent_to_tenant_at DATETIME NULL AFTER admin_reviewed_at,
    ADD COLUMN tenant_note VARCHAR(500) NULL AFTER sent_to_tenant_at,
    ADD KEY idx_agreement_stage (status),
    ADD KEY idx_agreement_owner (owner_name, status);

-- Route each agreement to the owner portal login that must sign it. Snapshotted
-- on the row because room_id is ON DELETE SET NULL — a delisted room must not
-- strand a signed agreement with no landlord on it.
UPDATE digital_agreements a
    JOIN rooms r ON r.id = a.room_id
SET a.owner_name = r.owner_name
WHERE a.owner_name IS NULL;

-- The hand-off trail. One row per action by whoever took it, so "who sent this
-- to the owner, and when did it come back" is answerable from the record
-- instead of inferred from timestamps.
CREATE TABLE IF NOT EXISTS agreement_events (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agreement_id INT UNSIGNED NOT NULL,
    actor_role   ENUM('admin', 'owner', 'tenant', 'system') NOT NULL,
    actor_name   VARCHAR(120) NULL,
    action       VARCHAR(40) NOT NULL,
    from_status  VARCHAR(20) NULL,
    to_status    VARCHAR(20) NULL,
    note         VARCHAR(500) NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_event_agreement (agreement_id, id),
    CONSTRAINT fk_event_agreement FOREIGN KEY (agreement_id) REFERENCES digital_agreements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
