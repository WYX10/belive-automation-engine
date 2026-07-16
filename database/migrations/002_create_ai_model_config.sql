-- Which model is live for each pipeline phase. Written by Admin → AI Models,
-- read by ModelRouter on every skill call — this is what makes model swapping
-- take effect immediately, per interaction.

CREATE TABLE IF NOT EXISTS ai_model_config (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    phase      ENUM('lead_gen', 'conversion', 'content_creation') NOT NULL UNIQUE,
    model_key  VARCHAR(60) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
