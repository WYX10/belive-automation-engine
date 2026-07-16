-- Tenure pricing: monthly (flexible, highest) · 6-month (mid) · 12-month+
-- (lowest, best value). Every room carries all three rows — a price is never
-- shown without its tenure.

CREATE TABLE IF NOT EXISTS room_pricing (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id       INT UNSIGNED NOT NULL,
    tenure        ENUM('monthly', '6_month', '12_month') NOT NULL,
    price         DECIMAL(8,2) NOT NULL,
    is_best_value TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_room_tenure (room_id, tenure),
    CONSTRAINT fk_pricing_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
