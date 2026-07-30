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

    /**
     * Models that answered "this model does not support assistant message
     * prefill". Remembered for the rest of the request so one rejection is
     * paid for once, not on every skill call in the same conversation.
     *
     * @var array<string, true>
     */
    private static array $noPrefill = [];

    public function generate(string $system, array $messages, array $opts = []): array
    {
        if (isset($opts['image'])) {
            $messages = self::withImage($messages, $opts['image']);
        }

        // Anthropic has no response_format switch. What it has instead is
        // assistant prefill: seed the reply with "{" and the model continues
        // the object rather than opening with "Here's the JSON:". Without this,
        // Claude's preamble/trailing commentary is the single biggest reason a
        // Claude-assigned phase logs "model returned unparseable output" and
        // silently falls back to defaults — which is how a decided photo send
        // quietly turned into a text-only message.
        //
        // Not every model accepts it: the reasoning models reject a prefilled
        // assistant turn outright with a 400. For those, the instruction below
        // does the same job in the system prompt.
        $wantsJson = (bool) ($opts['json'] ?? false);
        $prefill = $wantsJson && !isset(self::$noPrefill[$this->model]) ? '{' : null;

        try {
            $raw = $this->post($system, $messages, $opts, $prefill);
        } catch (BadResponseException $e) {
            $body = (string) $e->getResponse()->getBody();

            // A model that cannot be prefilled is a shape difference, not a
            // failure — ask again the way this one accepts.
            if ($prefill !== null && stripos($body, 'prefill') !== false) {
                self::$noPrefill[$this->model] = true;
                $prefill = null;
                $raw = $this->post($system, $messages, $opts, null);
            } else {
                throw new RuntimeException(
                    "Claude API error ({$e->getResponse()->getStatusCode()}): " . mb_substr($body, 0, 400),
                    0,
                    $e
                );
            }
        }

        $text = '';
        foreach ($raw['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        // The prefilled "{" is ours, not the model's — it never comes back in
        // the response, so put it back before anyone tries to decode this.
        if ($prefill !== null) {
            $text = $prefill . $text;
        }

        return [
            'text'      => trim($text),
            'raw'       => $raw,
            'model'     => $this->model,
            'truncated' => ($raw['stop_reason'] ?? '') === 'max_tokens',
        ];
    }

    /**
     * One Messages API call. $prefill seeds the assistant turn when the model
     * supports it; when it does not, the same requirement is stated in the
     * system prompt instead.
     *
     * @throws BadResponseException so the caller can tell a prefill rejection
     *                              from a genuine failure
     */
    private function post(string $system, array $messages, array $opts, ?string $prefill): array
    {
        if ($prefill !== null) {
            $messages[] = ['role' => 'assistant', 'content' => $prefill];
        } elseif ($opts['json'] ?? false) {
            $system .= "\n\nOutput format: reply with the JSON object and nothing else — no preamble,"
                . ' no explanation, no markdown code fence. The first character of your reply must be'
                . ' "{" and the last must be "}".';
        }

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

        return json_decode((string) $response->getBody(), true) ?? [];
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
