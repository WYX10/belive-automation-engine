-- Per-room electricity submetering. Every BeLive room has its OWN meter, so a
-- tenant pays for exactly the units their own room used — never a share of a
-- whole-unit bill split by headcount. These two tables are what the tenant
-- portal reads on /tenant/electric.
--
-- electric_meters — the meter fitted to a room, plus the rate it bills at.
-- electric_bills  — one issued billing period: the two readings it was worked
--                   out from, the rate applied at the time, and what is owed.
--
-- Readings are RECORDED, never invented. reading_source says where each pair
-- came from: 'smart_meter' (imported from BeLive's existing IoT stack — we
-- build zero hardware) or 'manual' (someone read the dial). Nothing here
-- simulates a live sensor feed.
--
-- The rate and standing charge are copied onto each bill rather than read
-- through the meter, so a later tariff change never silently rewrites a bill
-- the tenant has already seen (same immutable-snapshot rule as referrals).
--
-- 'overdue' is deliberately not a stored status: it is unpaid + due_on in the
-- past, derived at read time, so no cron job can leave it stale.

CREATE TABLE IF NOT EXISTS electric_meters (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id            INT UNSIGNED NOT NULL,
    meter_serial       VARCHAR(40) NOT NULL,
    tariff_rm_per_kwh  DECIMAL(6,4) NOT NULL DEFAULT 0.5000,
    standing_charge_rm DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    installed_on       DATE NULL,
    active             TINYINT(1) NOT NULL DEFAULT 1,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meter_room (room_id),
    UNIQUE KEY uq_meter_serial (meter_serial),
    CONSTRAINT fk_meter_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS electric_bills (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meter_id           INT UNSIGNED NOT NULL,
    -- The tenant this period is billed to. Required: a bill nobody is named on
    -- must never surface in someone else's portal.
    lead_id            INT UNSIGNED NOT NULL,
    period_start       DATE NOT NULL,
    period_end         DATE NOT NULL,
    previous_reading   DECIMAL(10,2) NOT NULL,
    current_reading    DECIMAL(10,2) NOT NULL,
    units_kwh          DECIMAL(10,2) NOT NULL,
    rate_rm_per_kwh    DECIMAL(6,4) NOT NULL,
    standing_charge_rm DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    amount_rm          DECIMAL(10,2) NOT NULL,
    status             ENUM('unpaid', 'paid', 'waived') NOT NULL DEFAULT 'unpaid',
    reading_source     ENUM('smart_meter', 'manual') NOT NULL DEFAULT 'manual',
    issued_on          DATE NOT NULL,
    due_on             DATE NOT NULL,
    paid_at            DATETIME NULL,
    notes              VARCHAR(255) NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bill_period (meter_id, period_start),
    KEY idx_bill_tenant (lead_id, period_start),
    KEY idx_bill_due (status, due_on),
    CONSTRAINT fk_bill_meter FOREIGN KEY (meter_id) REFERENCES electric_meters(id) ON DELETE CASCADE,
    CONSTRAINT fk_bill_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
