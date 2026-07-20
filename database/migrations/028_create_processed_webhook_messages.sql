-- Meta re-delivers a webhook whenever the previous attempt did not answer
-- fast enough, and every redelivery carries the same WhatsApp message id
-- (wamid). Recording each wamid on first sight lets the receiver drop
-- duplicates instead of running the AI pipeline (and replying) again.

CREATE TABLE IF NOT EXISTS processed_webhook_messages (
    wamid      VARCHAR(128) NOT NULL PRIMARY KEY,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pwm_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
