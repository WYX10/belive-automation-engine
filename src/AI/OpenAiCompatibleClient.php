<?php

declare(strict_types=1);

namespace App\AI;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use RuntimeException;

/**
 * Thin transport wrapper around any OpenAI-compatible chat completions API —
 * covers OpenAI itself and OpenRouter (which fronts many vendors behind the
 * same wire format). Construct with the provider's base URL.
 */
final class OpenAiCompatibleClient implements LlmClient
{
    private Client $http;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly string $providerLabel,
        private readonly string $maxTokensField = 'max_tokens',
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client(['timeout' => 60]);
    }

    public function generate(string $system, array $messages, array $opts = []): array
    {
        $maxTokens = $opts['max_tokens'] ?? 1024;

        try {
            $response = $this->http->post(rtrim($this->baseUrl, '/') . '/chat/completions', [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model'    => $this->model,
                    'messages' => [['role' => 'system', 'content' => $system], ...$messages],
                    // OpenAI's newer models require max_completion_tokens;
                    // OpenRouter documents max_tokens — caller picks the field.
                    $this->maxTokensField => $maxTokens,
                ] + (isset($opts['temperature']) ? ['temperature' => $opts['temperature']] : []),
            ]);
        } catch (BadResponseException $e) {
            $body = mb_substr((string) $e->getResponse()->getBody(), 0, 400);
            throw new RuntimeException("{$this->providerLabel} API error ({$e->getResponse()->getStatusCode()}): $body", 0, $e);
        }

        $raw = json_decode((string) $response->getBody(), true) ?? [];

        return [
            'text'  => trim($raw['choices'][0]['message']['content'] ?? ''),
            'raw'   => $raw,
            'model' => $this->model,
        ];
    }

    public function modelName(): string
    {
        return $this->model;
    }
}
