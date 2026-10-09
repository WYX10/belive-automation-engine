-- Default settings from MySQL migrations 030, 036, 043, 044, 045, 048.
INSERT INTO app_settings (setting_key, setting_value) VALUES
    ('content_auto_max', '3'),
    ('content_auto_platforms', 'facebook,instagram,tiktok'),
    ('content_brief', '')
 ON CONFLICT (setting_key) DO UPDATE SET  setting_key = app_settings.setting_key;

INSERT INTO app_settings (setting_key, setting_value) VALUES
    ('social_autoreply_enabled', '1'),
    ('social_reply_scope', 'enquiry'),
    ('social_wa_number', ''),
    ('social_wa_prefill', 'Hi beLive! I saw your {platform} post [{token}]'),
    ('social_comment_public_reply', 'Hi {name}! 🏠 Just sent you a DM with the details — check your inbox 💬'),
    ('social_comment_dm', E'Hi {name}! Thanks for your comment 🏠\n\nChat with Eve on WhatsApp for live availability, real prices and instant viewing booking:\n{link}\n\nSee you there! — beLive'),
    ('social_dm_reply', E'Hi {name}! Thanks for messaging beLive 🏠\n\nEve handles everything on WhatsApp — live room availability, honest prices and viewing slots you can book in one message:\n{link}\n\nTalk there? — beLive')
 ON CONFLICT (setting_key) DO UPDATE SET  setting_key = app_settings.setting_key;

INSERT INTO app_settings (setting_key, setting_value) VALUES
    ('content_schedule_default', 'suggested')
 ON CONFLICT (setting_key) DO UPDATE SET  setting_key = app_settings.setting_key;

INSERT INTO app_settings (setting_key, setting_value) VALUES
    ('engagement_refresh_minutes', '180')
 ON CONFLICT (setting_key) DO UPDATE SET  setting_key = app_settings.setting_key;

INSERT INTO app_settings (setting_key, setting_value) VALUES
    ('content_auto_enabled', '1'),
    ('content_auto_hour', '9'),
    ('content_auto_claimed_slot', ''),
    ('content_auto_last_started', ''),
    ('content_auto_last_finished', ''),
    ('content_auto_last_result', ''),
    ('content_auto_last_drafted', '0'),
    ('content_auto_last_trigger', '')
 ON CONFLICT (setting_key) DO UPDATE SET  setting_key = app_settings.setting_key;

INSERT INTO app_settings (setting_key, setting_value) VALUES
    ('content_publish_time', '18:00'),
    ('content_auto_publish_enabled', '0') ON CONFLICT DO NOTHING;

