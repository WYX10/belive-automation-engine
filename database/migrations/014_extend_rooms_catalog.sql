-- Phase 6.5 — extend the EXISTING rooms table (created in 009) into the full
-- public catalog, in place. The companion spec assumed no rooms table existed;
-- bookings/photos/pricing/portals already depend on this one, so we extend
-- rather than fork (reuse-don't-fork rule).
--
-- Pricing moves entirely to room_pricing (015): a price without a tenure is
-- exactly the ambiguity BeLive markets against, so rooms.price is dropped.
-- Photos and features move to room_images (016) / room_amenities (017).
-- Room types convert to BeLive's real vocabulary: single / middle / master.

ALTER TABLE rooms
    ADD COLUMN room_code VARCHAR(20) NULL UNIQUE AFTER id,
    ADD COLUMN property_name VARCHAR(120) NULL AFTER name,
    ADD COLUMN description TEXT NULL AFTER address,
    ADD COLUMN status ENUM('available', 'occupied', 'reserved') NOT NULL DEFAULT 'available',
    ADD COLUMN deposit_amount DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN available_from DATE NULL;

ALTER TABLE rooms CHANGE area location VARCHAR(100) NOT NULL;

-- room_type: widen the enum, map old values, then narrow to the real trio.
ALTER TABLE rooms MODIFY room_type ENUM('small', 'medium', 'master', 'studio', 'single', 'middle') NOT NULL;
UPDATE rooms SET room_type = 'single' WHERE room_type = 'small';
UPDATE rooms SET room_type = 'middle' WHERE room_type = 'medium';
UPDATE rooms SET room_type = 'master' WHERE room_type = 'studio';
ALTER TABLE rooms MODIFY room_type ENUM('single', 'middle', 'master') NOT NULL;

-- available flag -> status enum.
UPDATE rooms SET status = IF(available = 1, 'available', 'occupied');

ALTER TABLE rooms
    DROP COLUMN price,
    DROP COLUMN photos,
    DROP COLUMN features,
    DROP COLUMN available;

-- Channel #2 context: which room + tenure a website visitor enquired about,
-- and the tenure Eve recommends/learns during conversation.
ALTER TABLE leads
    ADD COLUMN enquired_room_id INT UNSIGNED NULL,
    ADD COLUMN preferred_tenure ENUM('monthly', '6_month', '12_month') NULL,
    ADD CONSTRAINT fk_leads_enquired_room FOREIGN KEY (enquired_room_id) REFERENCES rooms(id) ON DELETE SET NULL;
