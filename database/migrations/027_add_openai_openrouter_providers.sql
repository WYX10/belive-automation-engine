-- OpenAI + OpenRouter join Anthropic/Gemini as LLM providers. OpenRouter model
-- ids are namespaced ("vendor/model", sometimes ":variant"), so model_key
-- columns get headroom too.

ALTER TABLE api_credentials
    MODIFY service ENUM('whatsapp', 'anthropic', 'gemini', 'meta_graph', 'openai', 'openrouter') NOT NULL;

ALTER TABLE ai_custom_models
    MODIFY provider ENUM('anthropic', 'gemini', 'openai', 'openrouter') NOT NULL,
    MODIFY model_key VARCHAR(100) NOT NULL;

ALTER TABLE ai_model_config
    MODIFY model_key VARCHAR(100) NOT NULL;
