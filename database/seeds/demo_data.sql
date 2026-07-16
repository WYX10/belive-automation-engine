-- Demo seed data for finals day.
--
-- Rooms use BeLive's REAL portfolio locations (Cheras, Sepang, Sri Kembangan,
-- Sentul, Kuala Lumpur, Petaling Jaya, Batu Kawan, Johor) — a BeLive judge
-- recognises their own areas. Setapak rooms exist for the canonical
-- self-learning demo scenario promised in the proposal.
--
-- Photo URLs are public placeholders (picsum.photos) so image sends genuinely
-- work — SWAP THEM FOR REAL BELIVE ROOM PHOTOS before finals (setup guide §6).
--
-- The Setapak drop-off state is pre-loaded (3 leads that went silent after a
-- price quote >4h ago) so judges can watch the lesson being LEARNED live via
-- the learning job — the learned rule itself is deliberately NOT seeded.

-- ---------------------------------------------------------------- rooms
INSERT INTO rooms (name, area, room_type, price, photos, features, available) VALUES
('Casa Residenza A-12-3',   'Setapak',        'medium', 650.00, '["https://picsum.photos/seed/belive-setapak1/800/600","https://picsum.photos/seed/belive-setapak1b/800/600"]', '["Fully furnished","High-speed WiFi","Weekly cleaning"]', 1),
('PV21 Residence B-8-1',    'Setapak',        'small',  500.00, '["https://picsum.photos/seed/belive-setapak2/800/600"]', '["Fully furnished","Near LRT","High-speed WiFi"]', 1),
('Platinum Lake PV15 C-3',  'Setapak',        'master', 850.00, '["https://picsum.photos/seed/belive-setapak3/800/600"]', '["Private bathroom","Fully furnished","Weekly cleaning"]', 1),
('Maluri Court 5-2',        'Cheras',         'medium', 600.00, '["https://picsum.photos/seed/belive-cheras1/800/600"]', '["Fully furnished","Near MRT","High-speed WiFi"]', 1),
('EkoCheras Suite 18-5',    'Cheras',         'master', 900.00, '["https://picsum.photos/seed/belive-cheras2/800/600"]', '["Private bathroom","Gym & pool","Weekly cleaning"]', 1),
('Kota Warisan Homestay 2', 'Sepang',         'small',  450.00, '["https://picsum.photos/seed/belive-sepang1/800/600"]', '["Fully furnished","Near KLIA","Free parking"]', 1),
('The Atmosphere 7-1',      'Sri Kembangan',  'medium', 580.00, '["https://picsum.photos/seed/belive-srik1/800/600"]', '["Fully furnished","High-speed WiFi","Near The Mines"]', 1),
('Sentul Point A-20-9',     'Sentul',         'medium', 700.00, '["https://picsum.photos/seed/belive-sentul1/800/600"]', '["Fully furnished","Near LRT","Weekly cleaning"]', 1),
('Regalia Residence 30-2',  'Kuala Lumpur',   'master', 950.00, '["https://picsum.photos/seed/belive-kl1/800/600"]', '["KLCC view","Infinity pool","Private bathroom"]', 1),
('Icon City 12-7',          'Petaling Jaya',  'medium', 750.00, '["https://picsum.photos/seed/belive-pj1/800/600"]', '["Fully furnished","Near LRT","High-speed WiFi"]', 1),
('Aspen Vision City 3-2',   'Batu Kawan',     'small',  480.00, '["https://picsum.photos/seed/belive-bk1/800/600"]', '["Fully furnished","Near Design Village","Free parking"]', 1),
('Austin Suites 15-3',      'Johor',          'medium', 620.00, '["https://picsum.photos/seed/belive-johor1/800/600"]', '["Fully furnished","Near AEON","Weekly cleaning"]', 1),
('D''Summit Residences 9-9','Johor',          'studio', 880.00, '["https://picsum.photos/seed/belive-johor2/800/600"]', '["Private kitchenette","Fully furnished","Near Setia Tropika"]', 1);

-- Phase 9 (bonus): owner accounts for the owner portal + demo addresses.
UPDATE rooms SET owner_name = 'Encik Rahman',  address = CONCAT(name, ', ', area) WHERE area IN ('Setapak', 'Sentul');
UPDATE rooms SET owner_name = 'Ms Tan Li Hua', address = CONCAT(name, ', ', area) WHERE area IN ('Cheras', 'Johor');

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
('60170000101', 'Aisyah Rahman', 'whatsapp', 'new', 'Setapak', '650', 'medium', NOW() - INTERVAL 26 HOUR, NOW() - INTERVAL 26 HOUR),
('60170000102', 'Jason Lim',     'whatsapp', 'new', 'Setapak', '600', NULL,     NOW() - INTERVAL 20 HOUR, NOW() - INTERVAL 20 HOUR),
('60170000103', 'Priya Nair',    'website',  'new', 'Setapak', '700', 'medium', NOW() - INTERVAL 9 HOUR,  NOW() - INTERVAL 9 HOUR);

INSERT INTO ai_interactions (lead_id, phase, skill, model_used, direction, message_in, message_out, message_kind, reasoning, created_at)
SELECT l.id, 'conversion', 'understand', 'claude-sonnet-5', 'inbound', CONCAT('Hi, any room in Setapak? Budget around RM', l.budget), NULL, NULL, 'Room enquiry with area and budget.', l.created_at
FROM leads l WHERE l.wa_phone IN ('60170000101','60170000102','60170000103');

INSERT INTO ai_interactions (lead_id, phase, skill, model_used, direction, message_in, message_out, message_kind, reasoning, created_at)
SELECT l.id, 'conversion', 'automate', 'claude-sonnet-5', 'outbound', NULL, CONCAT('We have a medium room in Setapak at RM 650/month — fully furnished, WiFi, weekly cleaning. Want to book a viewing?'), 'price_quote', 'Answered directly with matching room and price.', l.created_at + INTERVAL 1 MINUTE
FROM leads l WHERE l.wa_phone IN ('60170000101','60170000102','60170000103');

-- ------------------------------------------ returning-customer memory demo
-- Daniel enquired two days ago; when he messages again, Eve recalls his name
-- and prior Sentul search in the opening line (LeadMemoryProfile).
INSERT INTO leads (wa_phone, name, source_channel, status, location, budget, room_type, tenant_profile, closing_probability, lead_signals, ai_recommendation, last_contact_at, created_at) VALUES
('60170000201', 'Daniel Wong', 'whatsapp', 'qualified', 'Sentul', '700', 'medium', 'working_professional', 68, '["gave a specific area","stated budget RM700","working professional"]', 'Offer the Sentul Point medium room and propose a viewing.', NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY);

INSERT INTO ai_interactions (lead_id, phase, skill, model_used, direction, message_in, message_out, message_kind, reasoning, created_at)
SELECT id, 'conversion', 'understand', 'claude-sonnet-5', 'inbound', 'Hi, looking for a medium room near Sentul, I work in KL Sentral, budget RM700', NULL, NULL, 'Working professional, area Sentul, budget RM700.', NOW() - INTERVAL 2 DAY FROM leads WHERE wa_phone = '60170000201';

INSERT INTO ai_interactions (lead_id, phase, skill, model_used, direction, message_in, message_out, message_kind, reasoning, created_at)
SELECT id, 'conversion', 'automate', 'claude-sonnet-5', 'outbound', NULL, 'Sentul Point A-20-9 fits perfectly — medium room, RM 700/month, 5 min to the LRT. Fully furnished, weekly cleaning. Want photos or a viewing?', 'reply', 'Matched room to professional profile near workplace.', NOW() - INTERVAL 2 DAY + INTERVAL 1 MINUTE FROM leads WHERE wa_phone = '60170000201';
