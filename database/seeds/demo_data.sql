-- Demo seed data for finals day.
--
-- Rooms mirror BeLive's REAL portfolio: real locations (Taman Maluri and
-- M Vertica in Cheras, PV9 Residence in Seri Kembangan, Sentul, Sepang, KL,
-- PJ, Batu Kawan, Johor — plus Setapak for the proposal's demo scenario),
-- real room types (single/middle/master), real amenity vocabulary (ibilik),
-- and the real observed price band RM 450–1,100/month. Every room has all
-- three tenure prices (monthly flexible highest, 12-month lowest = best
-- value) and RM 0 deposit — BeLive's headline differentiator.
--
-- Photo URLs are clearly-neutral placeholders (picsum.photos) so image sends
-- genuinely work — swap for BeLive's own marketing photos (with permission)
-- before finals; never hotlink their CDN or scrape ibilik tenant photos.
--
-- The Setapak drop-off state is pre-loaded (3 leads silent after a price
-- quote) so judges can watch the lesson being LEARNED live — the learned
-- rule itself is deliberately NOT seeded.

-- ---------------------------------------------------------------- rooms
INSERT INTO rooms (room_code, name, property_name, location, room_type, description, status, deposit_amount, available_from, owner_name, address) VALUES
('RM-101', 'A-12-3',  'Platinum Lake PV10',            'Setapak',        'middle', 'Fully furnished middle room with window, walking distance to LRT Wangsa Maju.', 'available', 0.00, CURDATE(), 'Encik Rahman', 'Platinum Lake PV10, Setapak'),
('RM-102', 'B-8-1',   'PV21 Residence',                'Setapak',        'single', 'Cozy single room, ideal for students — TARUMT bus at the doorstep.',            'available', 0.00, CURDATE(), 'Encik Rahman', 'PV21 Residence, Setapak'),
('RM-103', 'C-3-7',   'Platinum Lake PV15',            'Setapak',        'master', 'Master room with private bathroom and KL skyline view.',                        'available', 0.00, CURDATE(), 'Encik Rahman', 'Platinum Lake PV15, Setapak'),
('RM-104', 'A-20-9',  'Sentul Point Suite Apartments', 'Sentul',         'middle', 'Middle room 5 minutes from LRT Sentul Timur — perfect for KL Sentral commuters.', 'available', 0.00, CURDATE(), 'Encik Rahman', 'Sentul Point, Sentul'),
('RM-105', 'MK-5-2',  'Taman Maluri',                  'Cheras',         'master', 'Spacious master room with private bathroom, next to MRT Maluri and Sunway Velocity.', 'available', 0.00, CURDATE(), 'Ms Tan Li Hua', 'Taman Maluri, Cheras'),
('RM-106', 'MK-5-3',  'Taman Maluri',                  'Cheras',         'middle', 'Bright middle room in the same Maluri unit — shared bathroom, great housemates.', 'available', 0.00, CURDATE(), 'Ms Tan Li Hua', 'Taman Maluri, Cheras'),
('RM-107', 'T2-18-5', 'M Vertica KL City Residences',  'Cheras',         'single', 'Single room in a new high-rise — pool, gym, and MRT within reach.',              'available', 0.00, CURDATE(), 'Ms Tan Li Hua', 'M Vertica, Cheras'),
('RM-108', 'MC-9-2',  'Maluri Court',                  'Cheras',         'single', 'Value single room near AEON Maluri — everything you need downstairs.',           'available', 0.00, CURDATE(), 'Ms Tan Li Hua', 'Maluri Court, Cheras'),
('RM-109', 'PV9-11-6','PV9 Residence',                 'Seri Kembangan', 'single', 'Single room with balcony access, minutes from The Mines.',                       'available', 0.00, CURDATE(), NULL, 'PV9 Residence, Seri Kembangan'),
('RM-110', 'AT-7-1',  'The Atmosphere',                'Seri Kembangan', 'single', 'Budget-friendly single room above the Atmosphere commercial strip.',             'available', 0.00, CURDATE(), NULL, 'The Atmosphere, Seri Kembangan'),
('RM-111', 'KW-2-2',  'Kota Warisan',                  'Sepang',         'single', 'Single room near KLIA/klia2 — popular with airport and aviation staff.',        'available', 0.00, CURDATE(), NULL, 'Kota Warisan, Sepang'),
('RM-112', 'RG-30-2', 'Regalia Residence',             'Kuala Lumpur',   'master', 'Master room with infinity-pool access and KLCC view.',                           'available', 0.00, CURDATE(), NULL, 'Regalia Residence, Kuala Lumpur'),
('RM-113', 'IC-12-7', 'Icon City',                     'Petaling Jaya',  'middle', 'Middle room in Icon City — LRT, offices and food street below.',                 'available', 0.00, CURDATE(), NULL, 'Icon City, Petaling Jaya'),
('RM-114', 'AV-3-2',  'Aspen Vision City',             'Batu Kawan',     'single', 'Single room near Design Village and Batu Kawan industrial park.',                'available', 0.00, CURDATE(), NULL, 'Aspen Vision City, Batu Kawan'),
('RM-115', 'AS-15-3', 'Austin Suites',                 'Johor',          'middle', 'Middle room in Mount Austin — cafés, AEON and easy CIQ access.',                 'available', 0.00, CURDATE(), 'Ms Tan Li Hua', 'Austin Suites, Johor');

-- ------------------------------------------------- tenure pricing (RM/month)
-- monthly (flexible) highest · 6-month mid · 12-month lowest (best value).
INSERT INTO room_pricing (room_id, tenure, price, is_best_value)
SELECT id, 'monthly',  700, 0 FROM rooms WHERE room_code = 'RM-101' UNION ALL
SELECT id, '6_month',  650, 0 FROM rooms WHERE room_code = 'RM-101' UNION ALL
SELECT id, '12_month', 615, 1 FROM rooms WHERE room_code = 'RM-101' UNION ALL
SELECT id, 'monthly',  540, 0 FROM rooms WHERE room_code = 'RM-102' UNION ALL
SELECT id, '6_month',  500, 0 FROM rooms WHERE room_code = 'RM-102' UNION ALL
SELECT id, '12_month', 470, 1 FROM rooms WHERE room_code = 'RM-102' UNION ALL
SELECT id, 'monthly',  920, 0 FROM rooms WHERE room_code = 'RM-103' UNION ALL
SELECT id, '6_month',  850, 0 FROM rooms WHERE room_code = 'RM-103' UNION ALL
SELECT id, '12_month', 800, 1 FROM rooms WHERE room_code = 'RM-103' UNION ALL
SELECT id, 'monthly',  750, 0 FROM rooms WHERE room_code = 'RM-104' UNION ALL
SELECT id, '6_month',  700, 0 FROM rooms WHERE room_code = 'RM-104' UNION ALL
SELECT id, '12_month', 660, 1 FROM rooms WHERE room_code = 'RM-104' UNION ALL
SELECT id, 'monthly', 1100, 0 FROM rooms WHERE room_code = 'RM-105' UNION ALL
SELECT id, '6_month', 1050, 0 FROM rooms WHERE room_code = 'RM-105' UNION ALL
SELECT id, '12_month', 990, 1 FROM rooms WHERE room_code = 'RM-105' UNION ALL
SELECT id, 'monthly',  860, 0 FROM rooms WHERE room_code = 'RM-106' UNION ALL
SELECT id, '6_month',  800, 0 FROM rooms WHERE room_code = 'RM-106' UNION ALL
SELECT id, '12_month', 750, 1 FROM rooms WHERE room_code = 'RM-106' UNION ALL
SELECT id, 'monthly',  860, 0 FROM rooms WHERE room_code = 'RM-107' UNION ALL
SELECT id, '6_month',  800, 0 FROM rooms WHERE room_code = 'RM-107' UNION ALL
SELECT id, '12_month', 755, 1 FROM rooms WHERE room_code = 'RM-107' UNION ALL
SELECT id, 'monthly',  700, 0 FROM rooms WHERE room_code = 'RM-108' UNION ALL
SELECT id, '6_month',  650, 0 FROM rooms WHERE room_code = 'RM-108' UNION ALL
SELECT id, '12_month', 615, 1 FROM rooms WHERE room_code = 'RM-108' UNION ALL
SELECT id, 'monthly',  590, 0 FROM rooms WHERE room_code = 'RM-109' UNION ALL
SELECT id, '6_month',  550, 0 FROM rooms WHERE room_code = 'RM-109' UNION ALL
SELECT id, '12_month', 520, 1 FROM rooms WHERE room_code = 'RM-109' UNION ALL
SELECT id, 'monthly',  495, 0 FROM rooms WHERE room_code = 'RM-110' UNION ALL
SELECT id, '6_month',  465, 0 FROM rooms WHERE room_code = 'RM-110' UNION ALL
SELECT id, '12_month', 450, 1 FROM rooms WHERE room_code = 'RM-110' UNION ALL
SELECT id, 'monthly',  520, 0 FROM rooms WHERE room_code = 'RM-111' UNION ALL
SELECT id, '6_month',  480, 0 FROM rooms WHERE room_code = 'RM-111' UNION ALL
SELECT id, '12_month', 455, 1 FROM rooms WHERE room_code = 'RM-111' UNION ALL
SELECT id, 'monthly', 1020, 0 FROM rooms WHERE room_code = 'RM-112' UNION ALL
SELECT id, '6_month',  950, 0 FROM rooms WHERE room_code = 'RM-112' UNION ALL
SELECT id, '12_month', 895, 1 FROM rooms WHERE room_code = 'RM-112' UNION ALL
SELECT id, 'monthly',  810, 0 FROM rooms WHERE room_code = 'RM-113' UNION ALL
SELECT id, '6_month',  750, 0 FROM rooms WHERE room_code = 'RM-113' UNION ALL
SELECT id, '12_month', 705, 1 FROM rooms WHERE room_code = 'RM-113' UNION ALL
SELECT id, 'monthly',  530, 0 FROM rooms WHERE room_code = 'RM-114' UNION ALL
SELECT id, '6_month',  490, 0 FROM rooms WHERE room_code = 'RM-114' UNION ALL
SELECT id, '12_month', 460, 1 FROM rooms WHERE room_code = 'RM-114' UNION ALL
SELECT id, 'monthly',  670, 0 FROM rooms WHERE room_code = 'RM-115' UNION ALL
SELECT id, '6_month',  620, 0 FROM rooms WHERE room_code = 'RM-115' UNION ALL
SELECT id, '12_month', 585, 1 FROM rooms WHERE room_code = 'RM-115';

-- ---------------------------------------------------------------- media
-- ALL visuals are real BeLive unit/facility media (team-provided): 4 photos +
-- 5 stills extracted from the DJI walkthrough videos. Every room card shows a
-- genuine unit visual; .mp4 rows are video tours shown on the detail page.
-- Identical fit-out across units is BeLive's real model, so shared show-unit
-- shots across listings are honest demo data.
INSERT INTO room_images (room_id, image_path, sort_order)
SELECT id, '/assets/img/rooms/room-master-2.jpg', 0   FROM rooms WHERE room_code = 'RM-110' UNION ALL
SELECT id, '/assets/img/rooms/common-dinette.jpg', 1  FROM rooms WHERE room_code = 'RM-110' UNION ALL
SELECT id, '/assets/img/rooms/room-master.jpg', 0     FROM rooms WHERE room_code = 'RM-111' UNION ALL
SELECT id, '/assets/img/rooms/common-dining.jpg', 1   FROM rooms WHERE room_code = 'RM-111' UNION ALL
SELECT id, '/assets/img/rooms/room-cityview.jpg', 0   FROM rooms WHERE room_code = 'RM-114' UNION ALL
SELECT id, '/assets/img/rooms/common-dinette.jpg', 1  FROM rooms WHERE room_code = 'RM-114' UNION ALL
SELECT id, '/assets/img/rooms/room-bedroom.jpg', 0    FROM rooms WHERE room_code = 'RM-102' UNION ALL
SELECT id, '/assets/img/rooms/common-dining.jpg', 1   FROM rooms WHERE room_code = 'RM-102' UNION ALL
SELECT id, '/assets/img/rooms/room-cityview-2.jpg', 0 FROM rooms WHERE room_code = 'RM-109' UNION ALL
SELECT id, '/assets/img/rooms/common-dinette.jpg', 1  FROM rooms WHERE room_code = 'RM-109' UNION ALL
SELECT id, '/assets/img/rooms/room-master-2.jpg', 0   FROM rooms WHERE room_code = 'RM-115' UNION ALL
SELECT id, '/assets/img/rooms/common-dining.jpg', 1   FROM rooms WHERE room_code = 'RM-115' UNION ALL
SELECT id, '/assets/img/rooms/room-master.jpg', 0     FROM rooms WHERE room_code = 'RM-101' UNION ALL
SELECT id, '/assets/img/rooms/common-dining.jpg', 1   FROM rooms WHERE room_code = 'RM-101' UNION ALL
SELECT id, '/assets/img/rooms/room-cityview.jpg', 0   FROM rooms WHERE room_code = 'RM-108' UNION ALL
SELECT id, '/assets/img/rooms/common-dinette.jpg', 1  FROM rooms WHERE room_code = 'RM-108' UNION ALL
SELECT id, '/assets/img/rooms/room-bedroom.jpg', 0    FROM rooms WHERE room_code = 'RM-104' UNION ALL
SELECT id, '/assets/img/rooms/common-dinette.jpg', 1  FROM rooms WHERE room_code = 'RM-104' UNION ALL
SELECT id, '/assets/img/rooms/room-cityview-2.jpg', 0 FROM rooms WHERE room_code = 'RM-113' UNION ALL
SELECT id, '/assets/img/rooms/facility-gym.jpg', 1    FROM rooms WHERE room_code = 'RM-113' UNION ALL
SELECT id, '/assets/img/rooms/room-master-2.jpg', 0   FROM rooms WHERE room_code = 'RM-106' UNION ALL
SELECT id, '/assets/img/rooms/common-dining.jpg', 1   FROM rooms WHERE room_code = 'RM-106' UNION ALL
SELECT id, '/assets/img/rooms/room-bedroom.jpg', 0    FROM rooms WHERE room_code = 'RM-107' UNION ALL
SELECT id, '/assets/img/rooms/facility-pool.jpg', 1   FROM rooms WHERE room_code = 'RM-107' UNION ALL
SELECT id, '/assets/img/rooms/facility-gym.jpg', 2    FROM rooms WHERE room_code = 'RM-107' UNION ALL
SELECT id, '/assets/img/rooms/room-cityview.jpg', 0   FROM rooms WHERE room_code = 'RM-103' UNION ALL
SELECT id, '/assets/img/rooms/facility-pool.jpg', 1   FROM rooms WHERE room_code = 'RM-103' UNION ALL
SELECT id, '/assets/img/rooms/facility-gym.jpg', 2    FROM rooms WHERE room_code = 'RM-103' UNION ALL
SELECT id, '/assets/img/rooms/room-cityview-2.jpg', 0 FROM rooms WHERE room_code = 'RM-112' UNION ALL
SELECT id, '/assets/img/rooms/facility-pool.jpg', 1   FROM rooms WHERE room_code = 'RM-112' UNION ALL
SELECT id, '/assets/img/rooms/room-master.jpg', 0     FROM rooms WHERE room_code = 'RM-105' UNION ALL
SELECT id, '/assets/img/rooms/common-dining.jpg', 1   FROM rooms WHERE room_code = 'RM-105';

-- Video tours (compressed from the team's DJI walkthrough clips).
INSERT INTO room_images (room_id, image_path, sort_order)
SELECT id, '/assets/img/rooms/videos/tour-dining.mp4', 9       FROM rooms WHERE room_code IN ('RM-101', 'RM-104') UNION ALL
SELECT id, '/assets/img/rooms/videos/tour-master.mp4', 9       FROM rooms WHERE room_code IN ('RM-107', 'RM-105') UNION ALL
SELECT id, '/assets/img/rooms/videos/tour-bedroom-view.mp4', 9 FROM rooms WHERE room_code IN ('RM-103', 'RM-112');

-- -------------------------------------------------------------- amenities
-- Exact ibilik vocabulary. Base set for every room:
INSERT INTO room_amenities (room_id, amenity)
SELECT id, 'Air-Conditioning' FROM rooms UNION ALL
SELECT id, 'Wifi / Internet Access' FROM rooms UNION ALL
SELECT id, 'Washing Machine' FROM rooms UNION ALL
SELECT id, '24 hours security' FROM rooms;

-- Bathrooms: masters private, others shared.
INSERT INTO room_amenities (room_id, amenity)
SELECT id, 'Private Bathroom' FROM rooms WHERE room_type = 'master' UNION ALL
SELECT id, 'Share Bathroom' FROM rooms WHERE room_type <> 'master';

-- Location/facility extras.
INSERT INTO room_amenities (room_id, amenity)
SELECT id, 'Near LRT / MRT' FROM rooms WHERE room_code IN ('RM-101','RM-104','RM-105','RM-106','RM-107','RM-113') UNION ALL
SELECT id, 'Near Bus stop' FROM rooms WHERE room_code IN ('RM-102','RM-109','RM-110','RM-111','RM-114','RM-115') UNION ALL
SELECT id, 'Swimming Pools' FROM rooms WHERE room_code IN ('RM-103','RM-107','RM-112','RM-113') UNION ALL
SELECT id, 'Gymnasium Facility' FROM rooms WHERE room_code IN ('RM-103','RM-107','RM-112','RM-113') UNION ALL
SELECT id, 'Cooking Allowed' FROM rooms WHERE room_code IN ('RM-101','RM-104','RM-105','RM-106','RM-108','RM-115') UNION ALL
SELECT id, 'Covered car park' FROM rooms WHERE room_code IN ('RM-103','RM-105','RM-112','RM-115') UNION ALL
SELECT id, 'Mini Market' FROM rooms WHERE room_code IN ('RM-102','RM-108','RM-110','RM-113') UNION ALL
SELECT id, 'Surau' FROM rooms WHERE room_code IN ('RM-101','RM-111');

-- --------------------------------------------- per-phase model assignments
INSERT INTO ai_model_config (phase, model_key) VALUES
('lead_gen',         'gemini-3.5-flash'),
('conversion',       'claude-sonnet-5'),
('content_creation', 'claude-sonnet-5')
ON DUPLICATE KEY UPDATE model_key = VALUES(model_key);

-- --------------------------------- the canonical Setapak drop-off scenario
-- Three tenants asked about Setapak, were quoted a price, and went silent.
-- Running `php cron/learning_job.php` detects the 3/3 (100% ≥ 40%) pattern,
-- learns the photos-before-price sequencing rule LIVE, and the next Setapak
-- enquiry behaves differently — the exact scenario from the proposal.
INSERT INTO leads (wa_phone, name, source_channel, status, location, budget, room_type, last_contact_at, created_at) VALUES
('60170000101', 'Aisyah Rahman', 'whatsapp', 'new', 'Setapak', '650', 'middle', NOW() - INTERVAL 26 HOUR, NOW() - INTERVAL 26 HOUR),
('60170000102', 'Jason Lim',     'whatsapp', 'new', 'Setapak', '600', NULL,     NOW() - INTERVAL 20 HOUR, NOW() - INTERVAL 20 HOUR),
('60170000103', 'Priya Nair',    'website',  'new', 'Setapak', '700', 'middle', NOW() - INTERVAL 9 HOUR,  NOW() - INTERVAL 9 HOUR);

INSERT INTO ai_interactions (lead_id, phase, skill, model_used, direction, message_in, message_out, message_kind, reasoning, created_at)
SELECT l.id, 'conversion', 'understand', 'claude-sonnet-5', 'inbound', CONCAT('Hi, any room in Setapak? Budget around RM', l.budget), NULL, NULL, 'Room enquiry with area and budget.', l.created_at
FROM leads l WHERE l.wa_phone IN ('60170000101','60170000102','60170000103');

INSERT INTO ai_interactions (lead_id, phase, skill, model_used, direction, message_in, message_out, message_kind, reasoning, created_at)
SELECT l.id, 'conversion', 'automate', 'claude-sonnet-5', 'outbound', NULL, 'We have a middle room in Setapak at RM 650/mo on a 6-month stay — fully furnished, WiFi, zero deposit. Want to book a viewing?', 'price_quote', 'Answered directly with matching room and price.', l.created_at + INTERVAL 1 MINUTE
FROM leads l WHERE l.wa_phone IN ('60170000101','60170000102','60170000103');

-- ------------------------------------------ returning-customer memory demo
-- Daniel enquired two days ago; when he messages again, Eve recalls his name
-- and prior Sentul search in the opening line (LeadMemoryProfile).
INSERT INTO leads (wa_phone, name, source_channel, status, location, budget, room_type, tenant_profile, preferred_tenure, closing_probability, lead_signals, ai_recommendation, last_contact_at, created_at) VALUES
('60170000201', 'Daniel Wong', 'whatsapp', 'qualified', 'Sentul', '700', 'middle', 'working_professional', '6_month', 68, '["gave a specific area","stated budget RM700","working professional"]', 'Offer the Sentul Point middle room on a 6-month stay and propose a viewing.', NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY);

INSERT INTO ai_interactions (lead_id, phase, skill, model_used, direction, message_in, message_out, message_kind, reasoning, created_at)
SELECT id, 'conversion', 'understand', 'claude-sonnet-5', 'inbound', 'Hi, looking for a middle room near Sentul, I work in KL Sentral, budget RM700', NULL, NULL, 'Working professional, area Sentul, budget RM700.', NOW() - INTERVAL 2 DAY FROM leads WHERE wa_phone = '60170000201';

INSERT INTO ai_interactions (lead_id, phase, skill, model_used, direction, message_in, message_out, message_kind, reasoning, created_at)
SELECT id, 'conversion', 'automate', 'claude-sonnet-5', 'outbound', NULL, 'Sentul Point A-20-9 fits perfectly — middle room, RM 700/mo on a 6-month stay, 5 min to the LRT. Zero deposit, weekly cleaning. Want photos or a viewing?', 'reply', 'Matched room to professional profile near workplace.', NOW() - INTERVAL 2 DAY + INTERVAL 1 MINUTE FROM leads WHERE wa_phone = '60170000201';
