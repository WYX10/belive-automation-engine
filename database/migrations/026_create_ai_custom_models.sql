-- Admin-added models beyond the built-in registry in config/ai_models.php.
-- ModelRouter::registry() merges both; built-in keys always win on collision.

CREATE TABLE IF NOT EXISTS ai_custom_models (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    model_key  VARCHAR(60) NOT NULL UNIQUE,
    provider   ENUM('anthropic', 'gemini') NOT NULL,
    label      VARCHAR(100) NOT NULL,
    purpose    VARCHAR(200) NOT NULL DEFAULT '',
    cost_tier  ENUM('economy', 'standard', 'premium') NOT NULL DEFAULT 'standard',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
