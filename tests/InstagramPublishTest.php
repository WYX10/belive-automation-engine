<?php

declare(strict_types=1);

/**
 * Instagram container readiness — the two-step publish must become three.
 *
 * Meta fetches image_url server-side after the container is created, so
 * calling media_publish straight away returns "Media ID is not available"
 * (code 9007, subcode 2207027). Nothing here touches Meta: a Guzzle
 * MockHandler queues the exact Graph responses and records every request, so
 * what is asserted is the ordering guarantee — no publish before FINISHED.
 */

use App\Integrations\Social\InstagramPublisher;
use App\Models\ApiCredential;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

// The publisher only makes real calls when a credential is active; the key is
// encrypted at rest, so a key must exist even in the throwaway test database.
if (($_ENV['APP_ENCRYPTION_KEY'] ?? '') === '') {
    $_ENV['APP_ENCRYPTION_KEY'] = base64_encode(random_bytes(32));
}
// No page_id — pageAccessToken() then uses the stored token as-is (no HTTP).
$credentialId = ApiCredential::store('meta_graph', 'test ig', 'PAGE-TOKEN', ['ig_user_id' => 'IG-USER-1']);

/**
 * ArrayObject (not a plain array) so the recorded requests stay visible to the
 * caller after destructuring — Middleware::history keeps the same instance.
 *
 * @param array<int, Response> $responses
 * @return array{0: InstagramPublisher, 1: ArrayObject}
 */
$publisherWith = static function (array $responses): array {
    $stack = HandlerStack::create(new MockHandler($responses));
    $sent = new ArrayObject();
    $stack->push(Middleware::history($sent));

    // Zero poll delays: the readiness loop must be driven by status_code, not by waiting.
    return [new InstagramPublisher(new Client(['handler' => $stack]), [0, 0, 0]), $sent];
};

$json = static fn (array $body): Response => new Response(200, ['Content-Type' => 'application/json'], json_encode($body));

// ---- happy path: poll until FINISHED, then publish --------------------------
[$publisher, $sent] = $publisherWith([
    $json(['id' => 'CONTAINER-1']),           // POST /media
    $json(['status_code' => 'IN_PROGRESS']),  // GET  /CONTAINER-1
    $json(['status_code' => 'FINISHED']),     // GET  /CONTAINER-1
    $json(['id' => 'IG-POST-77']),            // POST /media_publish
]);
$result = $publisher->publish('Cosy room in Sepang', 'https://belive.example/assets/img/rooms/1.jpg');

check('IG publish returns the real post id', ($result['external_id'] ?? '') === 'IG-POST-77', json_encode($result));
check('IG publish is not a dry run', ($result['dry_run'] ?? true) === false);

$paths = array_map(static fn ($t) => $t['request']->getMethod() . ' ' . $t['request']->getUri()->getPath(), $sent->getArrayCopy());
check(
    'container is polled between create and publish',
    $paths === [
        'POST /v20.0/IG-USER-1/media',
        'GET /v20.0/CONTAINER-1',
        'GET /v20.0/CONTAINER-1',
        'POST /v20.0/IG-USER-1/media_publish',
    ],
    implode(' | ', $paths)
);
check(
    'status poll asks for status_code',
    str_contains($sent[1]['request']->getUri()->getQuery(), 'status_code'),
    $sent[1]['request']->getUri()->getQuery()
);

// ---- Meta could not fetch the image: fail, never publish --------------------
[$publisher, $sent] = $publisherWith([
    $json(['id' => 'CONTAINER-2']),
    $json(['status_code' => 'ERROR', 'status' => 'Error: only photo or video can be accepted as media type.']),
]);
$error = '';
try {
    $publisher->publish('caption', 'https://belive.example/broken.jpg');
} catch (Throwable $e) {
    $error = $e->getMessage();
}
check('unfetchable image fails with the Meta detail', str_contains($error, 'only photo or video'), $error);
check('unfetchable image never reaches media_publish', count($sent) === 2, (string) count($sent));

// ---- still preparing after the whole budget: fail for Retry, never publish --
[$publisher, $sent] = $publisherWith([
    $json(['id' => 'CONTAINER-3']),
    $json(['status_code' => 'IN_PROGRESS']),
    $json(['status_code' => 'IN_PROGRESS']),
    $json(['status_code' => 'IN_PROGRESS']),
]);
$error = '';
try {
    $publisher->publish('caption', 'https://belive.example/slow.jpg');
} catch (Throwable $e) {
    $error = $e->getMessage();
}
check('a never-ready container fails instead of publishing', str_contains($error, 'still preparing'), $error);
check('a never-ready container stops after its poll budget', count($sent) === 4, (string) count($sent));

// ---- residual race: FINISHED but publish still says "not available" ---------
$notReady = new Response(400, ['Content-Type' => 'application/json'], json_encode([
    'error' => ['message' => 'Media ID is not available', 'code' => 9007, 'error_subcode' => 2207027],
]));
[$publisher, $sent] = $publisherWith([
    $json(['id' => 'CONTAINER-4']),
    $json(['status_code' => 'FINISHED']),
    $notReady,                      // POST /media_publish — the reported failure
    $json(['id' => 'IG-POST-88']),  // retried once, succeeds
]);
$result = $publisher->publish('caption', 'https://belive.example/racy.jpg');
check('subcode 2207027 is retried once and succeeds', ($result['external_id'] ?? '') === 'IG-POST-88', json_encode($result));
check('the 2207027 retry re-posts media_publish', count($sent) === 4, (string) count($sent));

// ---- a different Graph error is not retried --------------------------------
[$publisher, $sent] = $publisherWith([
    $json(['id' => 'CONTAINER-5']),
    $json(['status_code' => 'FINISHED']),
    new Response(400, ['Content-Type' => 'application/json'], json_encode([
        'error' => ['message' => 'Invalid OAuth access token', 'code' => 190],
    ])),
]);
$error = '';
try {
    $publisher->publish('caption', 'https://belive.example/photo.jpg');
} catch (Throwable $e) {
    $error = $e->getMessage();
}
check('an unrelated Graph error surfaces immediately', str_contains($error, 'Invalid OAuth access token'), $error);
check('an unrelated Graph error is not retried', count($sent) === 3, (string) count($sent));

// Leave no active meta_graph credential behind for the later test files.
ApiCredential::update($credentialId, ['is_active' => 0]);
