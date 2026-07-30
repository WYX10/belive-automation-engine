<?php

declare(strict_types=1);

/**
 * A customer asked for photos, got the photos, and then got this:
 *
 *   wa send failed — HTTP 400 — [100] The parameter text.body is required.
 *   {"to":"6011…","type":"text","text":{"preview_url":false,"body":""}}
 *
 * The reply was sent with an empty body. Three things had to go wrong in a row,
 * and each of them looked like success to the next:
 *
 *  1. OpenRouter answers 200 with an error object in the body — routinely, for
 *     the rate limits on ':free' models — and that body carries no 'choices'.
 *     Read as `choices[0].message.content ?? ''` it is an empty string, not an
 *     error, so nothing was logged and nothing was retried.
 *  2. CreateSkill returned that empty string as the reply.
 *  3. WhatsAppClient posted it, and Meta told us what we should have known
 *     before spending the call.
 *
 * The failure surfaced as a WhatsApp problem when nothing was ever written.
 */

use App\AI\OpenAiCompatibleClient;
use App\Core\Database;
use App\Integrations\WhatsApp\WhatsAppClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

$openRouterStub = static function (array $responses, array &$sent): Client {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($sent));

    return new Client(['handler' => $stack]);
};

// ---- 1. an error delivered as HTTP 200 is still an error --------------------
$sent1 = [];
$rateLimited = new Response(200, [], (string) json_encode([
    'error' => ['code' => 429, 'message' => 'Rate limit exceeded: free-models-per-day'],
]));
$client1 = new OpenAiCompatibleClient('k', 'google/gemma-4-26b-a4b-it:free', 'https://openrouter.ai/api/v1', 'OpenRouter', 'max_tokens', $openRouterStub([$rateLimited], $sent1));

$caught = '';
try {
    $client1->generate('SYS', [['role' => 'user', 'content' => 'hi']]);
} catch (RuntimeException $e) {
    $caught = $e->getMessage();
}

check('a 200 carrying an error object raises, not returns ""', $caught !== '', 'no exception raised');
check('the raised error quotes what the provider said', str_contains($caught, 'Rate limit exceeded'), $caught);

// A 200 with neither error nor choices is just as unusable.
$sent2 = [];
$client2 = new OpenAiCompatibleClient('k', 'm', 'https://openrouter.ai/api/v1', 'OpenRouter', 'max_tokens', $openRouterStub([new Response(200, [], '{}')], $sent2));
$caught2 = '';
try {
    $client2->generate('SYS', [['role' => 'user', 'content' => 'hi']]);
} catch (RuntimeException $e) {
    $caught2 = $e->getMessage();
}
check('a body with no choices raises rather than returning ""', str_contains($caught2, 'no choices'), $caught2);

// ---- 2. the shapes a working reply can arrive in ----------------------------
$reply = static function (array $message) use ($openRouterStub): string {
    $sent = [];
    $c = new OpenAiCompatibleClient('k', 'm', 'https://openrouter.ai/api/v1', 'OpenRouter', 'max_tokens', $openRouterStub(
        [new Response(200, [], (string) json_encode(['choices' => [['message' => $message, 'finish_reason' => 'stop']]]))],
        $sent
    ));

    return $c->generate('SYS', [['role' => 'user', 'content' => 'hi']])['text'];
};

check('a plain string content is read', $reply(['content' => 'Room is available.']) === 'Room is available.');
check(
    'content split into typed parts is joined',
    $reply(['content' => [['type' => 'text', 'text' => 'Room is '], ['type' => 'text', 'text' => 'available.']]]) === 'Room is available.'
);
check(
    'a turn spent on reasoning still yields its words',
    $reply(['content' => '', 'reasoning' => 'Room is available.']) === 'Room is available.'
);
check('content wins over reasoning when both are present', $reply(['content' => 'Real', 'reasoning' => 'Draft']) === 'Real');

// ---- 3. an empty body never reaches Meta ------------------------------------
// Credentials are reported as present so that reaching the network is what a
// missing guard would do — the guard, not the lack of a key, has to be what
// stops an empty body.
$guardWa = new class extends WhatsAppClient {
    public function isConfigured(): bool
    {
        return true;
    }
};

$before = (int) Database::run("SELECT COUNT(*) FROM ai_activity_log WHERE action = 'wa_send_failed'")->fetchColumn();
$result = $guardWa->sendText('60123456789', '   ');
$after = (int) Database::run("SELECT COUNT(*) FROM ai_activity_log WHERE action = 'wa_send_failed'")->fetchColumn();

check('a blank reply is refused rather than sent', ($result['failed'] ?? false) === true);
check(
    'the refusal names the real cause, not Meta’s symptom',
    str_contains((string) ($result['error'] ?? ''), 'reply text was empty'),
    (string) ($result['error'] ?? '')
);
check('the refusal is recorded for the admin', $after === $before + 1);

$detail = (string) Database::run(
    "SELECT detail FROM ai_activity_log WHERE action = 'wa_send_failed' ORDER BY id DESC LIMIT 1"
)->fetchColumn();
check('the record still shows what would have been sent', str_contains($detail, '"body":""'), $detail);

// A real message passes the guard and carries on down the normal path — here
// that is the dry-run branch, since this environment has no live credential.
$ok = (new WhatsAppClient())->sendText('60123456789', 'Room is available.');
check('a normal reply passes the guard untouched', ($ok['dry_run'] ?? false) === true, json_encode($ok));
check('a normal reply is not marked failed', ($ok['failed'] ?? false) !== true);

// Whitespace-only is empty too — that was the shape the model actually returned.
$blank = $guardWa->sendText('60123456789', "\n  \t ");
check('a whitespace-only reply counts as empty', ($blank['failed'] ?? false) === true);
