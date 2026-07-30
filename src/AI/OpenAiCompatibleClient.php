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
        if (isset($opts['image'])) {
            $messages = self::withImage($messages, $opts['image']);
        }

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
                ]
                + (isset($opts['temperature']) ? ['temperature' => $opts['temperature']] : [])
                // Same structured-output contract the other providers honour:
                // a skill that parses JSON gets JSON, whichever model the admin
                // assigned to the phase. (Every JSON system prompt here names
                // "JSON object", which is what this mode requires.)
                + (($opts['json'] ?? false) ? ['response_format' => ['type' => 'json_object']] : []),
            ]);
        } catch (BadResponseException $e) {
            $body = mb_substr((string) $e->getResponse()->getBody(), 0, 400);
            throw new RuntimeException("{$this->providerLabel} API error ({$e->getResponse()->getStatusCode()}): $body", 0, $e);
        }

        $raw = json_decode((string) $response->getBody(), true) ?? [];

        return [
            'text'      => trim($raw['choices'][0]['message']['content'] ?? ''),
            'raw'       => $raw,
            'model'     => $this->model,
            'truncated' => ($raw['choices'][0]['finish_reason'] ?? '') === 'length',
        ];
    }

    public function modelName(): string
    {
        return $this->model;
    }

    /**
     * Attach the image to the last user turn as a data: URL — the shape both
     * OpenAI and OpenRouter accept for vision models.
     *
     * @param array $messages
     * @param array{mime:string, data:string} $image
     */
    private static function withImage(array $messages, array $image): array
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') !== 'user' || !is_string($messages[$i]['content'])) {
                continue;
            }
            $messages[$i]['content'] = [
                ['type' => 'text', 'text' => $messages[$i]['content']],
                ['type' => 'image_url', 'image_url' => [
                    'url' => 'data:' . $image['mime'] . ';base64,' . $image['data'],
                ]],
            ];
            break;
        }

        return $messages;
    }
}
