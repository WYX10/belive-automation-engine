-- The renewal conversation, in the system instead of in a WhatsApp thread.
--
-- A tenancy period lives on digital_agreements (starts_on / ends_on, migration
-- 020). Until now nothing happened when one ran out: the owner had no view of
-- who was ending, and no way to keep them. renewal_offers is that move — in the
-- last 30 days of a tenancy the owner may offer THAT tenant a promotional rent
-- for a new term, and the tenant accepts or declines it in their own portal.
--
-- An accepted offer records intent, nothing more. It never edits the signed
-- agreement and never issues a new one: BeLive drafts the renewal agreement
-- afterwards, through the same admin -> owner -> tenant round trip as any other.
--
-- current_rent_rm is SNAPSHOTTED off the agreement at offer time. Re-pricing the
-- room later must never rewrite the saving a tenant has already been shown —
-- the same immutable-snapshot rule as digital_agreements.monthly_rent_rm (035)
-- and referral redemptions (021).
--
-- 'expired' is deliberately not a stored status: it is 'offered' with expires_on
-- in the past, derived at read time (RenewalOffer::isOpen), so no cron job can
-- leave it stale. Same reasoning as electric_bills 'overdue' (034).
--
-- One open offer per tenancy is enforced in RenewalOfferManager inside a
-- FOR UPDATE transaction — MySQL has no partial unique index, and a unique key
-- on (agreement_id, status) would also forbid a second DECLINED offer, which is
-- exactly what an owner should be able to make after a first price was refused.

CREATE TABLE IF NOT EXISTS renewal_offers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agreement_id    INT UNSIGNED NOT NULL,
    lead_id         INT UNSIGNED NOT NULL,
    room_id         INT UNSIGNED NULL,
    -- Routing key for the owner portal, same string identity as
    -- digital_agreements.owner_name / rooms.owner_name. There is no owners table.
    owner_name      VARCHAR(120) NOT NULL,
    tenure          ENUM('monthly', '6_month', '12_month') NOT NULL,
    current_rent_rm DECIMAL(10,2) NOT NULL,
    promo_rent_rm   DECIMAL(10,2) NOT NULL,
    -- The proposed new term: starts the day after the current one ends.
    starts_on       DATE NOT NULL,
    ends_on         DATE NOT NULL,
    message         VARCHAR(500) NULL,
    -- When the offer lapses. Defaults to the current tenancy's end date.
    expires_on      DATE NOT NULL,
    status          ENUM('offered', 'accepted', 'declined', 'withdrawn') NOT NULL DEFAULT 'offered',
    responded_at    DATETIME NULL,
    response_note   VARCHAR(500) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_offer_agreement (agreement_id, status),
    KEY idx_offer_lead (lead_id, status),
    KEY idx_offer_owner (owner_name, status),
    CONSTRAINT fk_offer_agreement FOREIGN KEY (agreement_id) REFERENCES digital_agreements(id) ON DELETE CASCADE,
    CONSTRAINT fk_offer_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    CONSTRAINT fk_offer_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
