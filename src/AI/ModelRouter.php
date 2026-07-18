<?php

declare(strict_types=1);

namespace App\AI;

use App\Core\Database;
use App\Models\ApiCredential;
use RuntimeException;

/**
 * Resolves "which model handles this phase right now" from ai_model_config
 * (set in Admin → AI Models) and hands back a ready client. Every AI skill
 * gets its client here — never instantiates one directly — which is what
 * makes admin model-swapping take effect live, per interaction.
 */
final class ModelRouter
{
    /** Sensible defaults until the admin assigns models per phase. */
    private const DEFAULTS = [
        'lead_gen'         => 'gemini-3.5-flash',
        'conversion'       => 'claude-sonnet-5',
        'content_creation' => 'claude-sonnet-5',
    ];

    public static function modelForPhase(string $phase): string
    {
        if (!in_array($phase, AI_PHASES, true)) {
            throw new RuntimeException("Unknown AI phase: $phase");
        }

        $row = Database::run(
            'SELECT model_key FROM ai_model_config WHERE phase = ? LIMIT 1',
            [$phase]
        )->fetch();

        return $row['model_key'] ?? self::DEFAULTS[$phase];
    }

    public static function clientForPhase(string $phase): LlmClient
    {
        return self::makeClient(self::modelForPhase($phase));
    }

    /**
     * Full model registry: built-ins from config/ai_models.php plus
     * admin-added rows from ai_custom_models. Built-in keys win on collision.
     */
    public static function registry(): array
    {
        $registry = require APP_ROOT . '/config/ai_models.php';

        foreach (Database::run('SELECT * FROM ai_custom_models ORDER BY id')->fetchAll() as $row) {
            if (isset($registry[$row['model_key']])) {
                continue;
            }
            $registry[$row['model_key']] = [
                'provider'  => $row['provider'],
                'label'     => $row['label'],
                'purpose'   => $row['purpose'],
                'cost_tier' => $row['cost_tier'],
                'custom_id' => (int) $row['id'],
            ];
        }

        return $registry;
    }

    public static function makeClient(string $modelKey): LlmClient
    {
        // ⚠ Offline stub for local pipeline testing only (MOCK_AI=true in .env).
        // Never enabled for the judge demo — see MockClient's header comment.
        if (filter_var($_ENV['MOCK_AI'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return new MockClient();
        }

        $meta = self::registry()[$modelKey]
            ?? throw new RuntimeException("Model '$modelKey' is not in the model registry.");

        $key = ApiCredential::decryptedKeyFor($meta['provider'])
            ?? throw new RuntimeException(
                "No active API credential for provider '{$meta['provider']}'. "
                . 'Add one in Admin → API Credentials.'
            );

        return match ($meta['provider']) {
            'anthropic'  => new ClaudeClient($key, $modelKey),
            'gemini'     => new GeminiClient($key, $modelKey),
            'openai'     => new OpenAiCompatibleClient($key, $modelKey, 'https://api.openai.com/v1', 'OpenAI', 'max_completion_tokens'),
            'openrouter' => new OpenAiCompatibleClient($key, $modelKey, 'https://openrouter.ai/api/v1', 'OpenRouter'),
            default      => throw new RuntimeException("No client for provider '{$meta['provider']}'."),
        };
    }
}
