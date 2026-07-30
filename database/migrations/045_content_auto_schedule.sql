-- The daily content run's own state, so "once a day" is a fact in the database
-- rather than a promise about something outside the app calling the cron file
-- (App Service Linux has no crontab, and nothing ever did).
--
--   content_auto_enabled     the Stop / Start button in the content studio
--   content_auto_hour        local hour the day's run is owed from
--   content_auto_claimed_slot the slot the last run took — the once-a-day guard;
--                            written with a conditional UPDATE so that of two
--                            callers racing (a page load and the scheduler)
--                            exactly one drafts
--   content_auto_last_*      what the studio card reports about the last run

INSERT INTO app_settings (setting_key, setting_value) VALUES
    ('content_auto_enabled', '1'),
    ('content_auto_hour', '9'),
    ('content_auto_claimed_slot', ''),
    ('content_auto_last_started', ''),
    ('content_auto_last_finished', ''),
    ('content_auto_last_result', ''),
    ('content_auto_last_drafted', '0'),
    ('content_auto_last_trigger', '')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
