<?php

declare(strict_types=1);

namespace App\AI;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use RuntimeException;

/**
 * Thin transport wrapper around the Anthropic Messages API. No business
 * logic — construct with a just-decrypted key, call generate(), get text.
 */
final class ClaudeClient implements LlmClient
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    private Client $http;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        ?Client $http = null,
    ) {
        $this->http = $http ?? new Client(['timeout' => 60]);
    }

    public function generate(string $system, array $messages, array $opts = []): array
    {
        if (isset($opts['image'])) {
            $messages = self::withImage($messages, $opts['image']);
        }

        try {
            $response = $this->http->post(self::ENDPOINT, [
                'headers' => [
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                    'content-type'      => 'application/json',
                ],
                // No 'temperature': current Claude models (Sonnet 5, Opus 4.8 and
                // newer) reject it outright — steer them with the prompt instead.
                // Callers still pass it; the other providers' clients honour it.
                'json' => [
                    'model'      => $this->model,
                    'max_tokens' => $opts['max_tokens'] ?? 1024,
                    'system'     => $system,
                    'messages'   => $messages,
                ],
            ]);
        } catch (BadResponseException $e) {
            $body = mb_substr((string) $e->getResponse()->getBody(), 0, 400);
            throw new RuntimeException("Claude API error ({$e->getResponse()->getStatusCode()}): $body", 0, $e);
        }

        $raw = json_decode((string) $response->getBody(), true) ?? [];

        $text = '';
        foreach ($raw['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        return ['text' => trim($text), 'raw' => $raw, 'model' => $this->model];
    }

    public function modelName(): string
    {
        return $this->model;
    }

    /**
     * Attach the image to the last user turn as an Anthropic content block.
     * The image goes before the text — Anthropic's own guidance for
     * "look at this, then answer".
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
                ['type' => 'image', 'source' => [
                    'type'       => 'base64',
                    'media_type' => $image['mime'],
                    'data'       => $image['data'],
                ]],
                ['type' => 'text', 'text' => $messages[$i]['content']],
            ];
            break;
        }

        return $messages;
    }
}
