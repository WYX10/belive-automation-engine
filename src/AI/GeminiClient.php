<?php

declare(strict_types=1);

namespace App\AI;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use RuntimeException;

/**
 * Thin transport wrapper around the Gemini generateContent API. The key is
 * sent as a header, never a query parameter, so it can't leak into URL logs.
 */
final class GeminiClient implements LlmClient
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta/models';

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
        $contents = array_map(static fn (array $m) => [
            'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m['content']]],
        ], $messages);

        try {
            $response = $this->http->post(self::BASE . "/{$this->model}:generateContent", [
                'headers' => [
                    'x-goog-api-key' => $this->apiKey,
                    'content-type'   => 'application/json',
                ],
                'json' => [
                    'system_instruction' => ['parts' => [['text' => $system]]],
                    'contents'           => $contents,
                    'generationConfig'   => array_filter([
                        'maxOutputTokens' => $opts['max_tokens'] ?? 1024,
                        'temperature'     => $opts['temperature'] ?? null,
                    ], static fn ($v) => $v !== null),
                ],
            ]);
        } catch (BadResponseException $e) {
            $body = mb_substr((string) $e->getResponse()->getBody(), 0, 400);
            throw new RuntimeException("Gemini API error ({$e->getResponse()->getStatusCode()}): $body", 0, $e);
        }

        $raw = json_decode((string) $response->getBody(), true) ?? [];

        $text = '';
        foreach ($raw['candidates'][0]['content']['parts'] ?? [] as $part) {
            $text .= $part['text'] ?? '';
        }

        return ['text' => trim($text), 'raw' => $raw, 'model' => $this->model];
    }

    public function modelName(): string
    {
        return $this->model;
    }
}
