-- API keys for WhatsApp / Claude / Gemini / Meta Graph. encrypted_key is
-- AES-256-GCM ciphertext (see src/Core/Encryption.php) — plaintext keys never
-- touch the database. meta holds provider extras (phone_number_id, waba_id).

CREATE TABLE IF NOT EXISTS api_credentials (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service         ENUM('whatsapp', 'anthropic', 'gemini', 'meta_graph') NOT NULL,
    label           VARCHAR(100) NOT NULL DEFAULT '',
    encrypted_key   TEXT NOT NULL,
    meta            JSON NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 0,
    last_tested_at  DATETIME NULL,
    test_status     ENUM('ok', 'failed') NULL,
    test_detail     VARCHAR(500) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_service_active (service, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
