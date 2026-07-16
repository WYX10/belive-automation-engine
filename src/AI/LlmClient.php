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
     * @param array  $opts     max_tokens?, temperature?, mock_hint? (ignored by real clients)
     *
     * @return array{text: string, raw: array, model: string}
     */
    public function generate(string $system, array $messages, array $opts = []): array;

    public function modelName(): string;
}
