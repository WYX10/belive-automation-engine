-- One persistent requirement profile per tenant, separate from shared lessons.
CREATE TABLE IF NOT EXISTS tenant_requirements (
    lead_id INT UNSIGNED NOT NULL PRIMARY KEY,
    location VARCHAR(100) NULL,
    budget DECIMAL(10,2) NULL,
    move_in_date VARCHAR(100) NULL,
    room_type VARCHAR(60) NULL,
    tenure VARCHAR(12) NULL,
    tenant_profile VARCHAR(40) NULL,
    occupants TINYINT UNSIGNED NULL,
    amenities JSON NULL,
    preferences JSON NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_requirements_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tenant_requirements
    (lead_id, location, budget, move_in_date, room_type, tenure, tenant_profile)
SELECT id, location,
    CASE WHEN budget REGEXP '^[0-9]{1,8}([.][0-9]{1,2})?$' THEN NULLIF(CAST(budget AS DECIMAL(10,2)), 0) ELSE NULL END,
    move_in_date, room_type, preferred_tenure, LEFT(tenant_profile, 40) FROM leads;
