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
     *                         json? => true asks the provider for a bare JSON object using
     *                         whatever mechanism it offers (OpenAI/Gemini have a JSON mode,
     *                         Anthropic has assistant prefill). Skills that parse JSON must set
     *                         it, so swapping a phase's model in the admin panel cannot change
     *                         whether the answer comes back parseable.
     *
     * @return array{text: string, raw: array, model: string, truncated: bool}
     *         truncated = the provider stopped on the token budget, so the text is a fragment.
     */
    public function generate(string $system, array $messages, array $opts = []): array;

    public function modelName(): string;
}
