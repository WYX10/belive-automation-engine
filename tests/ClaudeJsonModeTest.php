<?php

declare(strict_types=1);

/**
 * Anthropic has no response_format switch, so a skill that needs JSON asks for
 * it by prefilling the assistant turn with "{" — the model then continues the
 * object instead of opening with "Here's the JSON:".
 *
 * Not every model accepts that. The reasoning models answer a prefilled
 * assistant turn with a flat 400 ("this model does not support assistant
 * message prefill"), which took down the whole conversation: an exception out
 * of ModelRouter's client is not a fallback, it is a customer waiting for a
 * reply that never comes.
 *
 * What is asserted here is that the client asks each model the way that model
 * accepts, and that a real error still surfaces as one.
 */

use App\AI\ClaudeClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/** A Claude client wired to canned responses, with every request recorded. */
$claudeStub = static function (array $responses, array &$sent): Client {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($sent));

    return new Client(['handler' => $stack]);
};

$okBody = static fn (string $text, string $stop = 'end_turn'): string => (string) json_encode([
    'content'     => [['type' => 'text', 'text' => $text]],
    'stop_reason' => $stop,
]);

$prefillRefusal = new GuzzleHttp\Exception\BadResponseException(
    'Bad Request',
    new Request('POST', 'https://api.anthropic.com/v1/messages'),
    new Response(400, [], (string) json_encode([
        'type'  => 'error',
        'error' => [
            'type'    => 'invalid_request_error',
            'message' => 'This model does not support assistant message prefill. The final message'
                . ' must not be from the assistant.',
        ],
    ]))
);

// ---- 1. a model that accepts prefill gets prefilled --------------------------
$sentA = [];
$clientA = new ClaudeClient('k', 'claude-prefill-ok', $claudeStub([new Response(200, [], $okBody('"qualified": true}'))], $sentA));
$resultA = $clientA->generate('SYS', [['role' => 'user', 'content' => 'hi']], ['json' => true, 'max_tokens' => 100]);

$bodyA = json_decode((string) $sentA[0]['request']->getBody(), true);
$lastA = end($bodyA['messages']);

check('a JSON call seeds the assistant turn', ($lastA['role'] ?? '') === 'assistant', json_encode($lastA));
check('the seed is a bare opening brace', ($lastA['content'] ?? '') === '{');
check(
    'the seeded brace is restored onto the reply',
    $resultA['text'] === '{"qualified": true}',
    $resultA['text']
);
check('a complete reply is not reported as truncated', $resultA['truncated'] === false);

// ---- 2. a model that refuses prefill is asked again, its way -----------------
$sentB = [];
$clientB = new ClaudeClient('k', 'claude-no-prefill', $claudeStub(
    [$prefillRefusal, new Response(200, [], $okBody('{"qualified": false}'))],
    $sentB
));
$resultB = $clientB->generate('SYS', [['role' => 'user', 'content' => 'hi']], ['json' => true]);

check('a prefill refusal is retried, not thrown', count($sentB) === 2, 'requests made: ' . count($sentB));

$bodyB = json_decode((string) $sentB[1]['request']->getBody(), true);
$lastB = end($bodyB['messages']);

check('the retry sends no assistant turn', ($lastB['role'] ?? '') === 'user', json_encode($lastB));
check(
    'the retry asks for bare JSON in the system prompt instead',
    str_contains((string) $bodyB['system'], 'JSON object and nothing else'),
    (string) $bodyB['system']
);
check('the retry’s answer is returned whole', $resultB['text'] === '{"qualified": false}', $resultB['text']);
check('nothing is prepended when there was no prefill', substr_count($resultB['text'], '{') === 1);

// ---- 3. the refusal is remembered, so it is paid for once -------------------
$sentC = [];
$clientC = new ClaudeClient('k', 'claude-no-prefill', $claudeStub([new Response(200, [], $okBody('{"ok": 1}'))], $sentC));
$clientC->generate('SYS', [['role' => 'user', 'content' => 'again']], ['json' => true]);

$bodyC = json_decode((string) $sentC[0]['request']->getBody(), true);
$lastC = end($bodyC['messages']);

check('a model known to refuse prefill is never prefilled again', ($lastC['role'] ?? '') === 'user');
check('one request, not two, on the second call', count($sentC) === 1, 'requests made: ' . count($sentC));

// ---- 4. non-JSON calls are untouched ----------------------------------------
$sentD = [];
$clientD = new ClaudeClient('k', 'claude-prefill-ok', $claudeStub([new Response(200, [], $okBody('Hi there'))], $sentD));
$resultD = $clientD->generate('SYS', [['role' => 'user', 'content' => 'hi']], []);

$bodyD = json_decode((string) $sentD[0]['request']->getBody(), true);

check('a plain reply call sends no assistant turn', count($bodyD['messages']) === 1);
check('a plain reply call leaves the system prompt alone', $bodyD['system'] === 'SYS');
check('a plain reply comes back as written', $resultD['text'] === 'Hi there');

// ---- 5. a genuine failure still fails ---------------------------------------
$authFailure = new GuzzleHttp\Exception\BadResponseException(
    'Unauthorized',
    new Request('POST', 'https://api.anthropic.com/v1/messages'),
    new Response(401, [], (string) json_encode(['error' => ['message' => 'invalid x-api-key']]))
);

$sentE = [];
$clientE = new ClaudeClient('bad', 'claude-prefill-ok', $claudeStub([$authFailure], $sentE));

$threw = '';
try {
    $clientE->generate('SYS', [['role' => 'user', 'content' => 'hi']], ['json' => true]);
} catch (RuntimeException $e) {
    $threw = $e->getMessage();
}

check('a real API error is not swallowed as a prefill problem', str_contains($threw, '401'), $threw);
check('the error says what the API said', str_contains($threw, 'invalid x-api-key'), $threw);
check('a real error is not retried', count($sentE) === 1, 'requests made: ' . count($sentE));

// ---- 6. truncation is reported so the caller can retry bigger ---------------
$sentF = [];
$clientF = new ClaudeClient('k', 'claude-prefill-ok', $claudeStub(
    [new Response(200, [], $okBody('"qualified": true, "reasoning": "the customer', 'max_tokens'))],
    $sentF
));
$resultF = $clientF->generate('SYS', [['role' => 'user', 'content' => 'hi']], ['json' => true]);

check('an answer cut off by the budget is flagged truncated', $resultF['truncated'] === true);
check(
    'and what did arrive is still repaired into usable fields',
    (App\AI\Skills\SkillSupport::extractJson($resultF['text']) ?? [])['qualified'] === true,
    $resultF['text']
);
