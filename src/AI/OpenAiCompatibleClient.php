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

        // A failure does not always arrive as a failure status. OpenRouter
        // answers 200 with an error object in the body — routinely, for the
        // rate limits on ':free' models — and that body carries no 'choices'
        // at all. Read naively it yields an empty string, which is how an
        // empty WhatsApp message reached Meta and came back "[100] The
        // parameter text.body is required".
        if (isset($raw['error'])) {
            $message = is_array($raw['error'])
                ? (string) ($raw['error']['message'] ?? json_encode($raw['error'], JSON_UNESCAPED_UNICODE))
                : (string) $raw['error'];

            throw new RuntimeException("{$this->providerLabel} error: " . mb_substr($message, 0, 400));
        }

        $choice = $raw['choices'][0] ?? null;
        if ($choice === null) {
            throw new RuntimeException(
                "{$this->providerLabel} returned no choices for model '{$this->model}': "
                . mb_substr((string) json_encode($raw, JSON_UNESCAPED_UNICODE), 0, 300)
            );
        }

        return [
            'text'      => self::textOf($choice['message'] ?? []),
            'raw'       => $raw,
            'model'     => $this->model,
            'truncated' => ($choice['finish_reason'] ?? '') === 'length',
        ];
    }

    /**
     * The assistant's words, whichever shape this provider used. `content` is
     * usually a string but comes back as an array of typed parts from some
     * models behind OpenRouter; a reasoning model can also leave `content`
     * empty and put everything in `reasoning`.
     */
    private static function textOf(array $message): string
    {
        $content = $message['content'] ?? '';

        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                $parts[] = is_array($part) ? (string) ($part['text'] ?? '') : (string) $part;
            }
            $content = implode('', $parts);
        }

        $content = trim((string) $content);

        return $content !== '' ? $content : trim((string) ($message['reasoning'] ?? ''));
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
