-- Room amenities (replaces the rooms.features JSON column). Values use
-- BeLive's exact ibilik vocabulary, e.g. 'Air-Conditioning',
-- 'Wifi / Internet Access', 'Near LRT / MRT', '24 hours security'.

CREATE TABLE IF NOT EXISTS room_amenities (
    id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL,
    amenity VARCHAR(60) NOT NULL,
    INDEX idx_room (room_id),
    CONSTRAINT fk_amenities_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
