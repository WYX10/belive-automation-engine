<?php

declare(strict_types=1);

namespace App\AI;

/**
 * Common surface every model client implements, so skills can stay
 * provider-agnostic and the admin panel's model swapping works live.
 */
interface LlmClient
{
    /**
     * @param string $system   System prompt.
     * @param array  $messages [['role' => 'user'|'assistant', 'content' => string], ...]
     * @param array  $opts     max_tokens?, temperature? (ignored by ClaudeClient — current
     *                         Claude models reject it), mock_hint? (ignored by real clients),
     *                         image? => ['mime' => string, 'data' => base64 string] — attached
     *                         to the last user message in whatever shape the provider expects,
     *                         so a caller can show a model a photo without knowing the wire format.
     *
     * @return array{text: string, raw: array, model: string}
     */
    public function generate(string $system, array $messages, array $opts = []): array;

    public function modelName(): string;
}
