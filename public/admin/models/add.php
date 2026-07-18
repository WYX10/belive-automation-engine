<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\AI\ModelRouter;
use App\Core\Auth;
use App\Core\Database;
use App\Models\ApiCredential;
use GuzzleHttp\Client;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();
Auth::requireCsrf();

$modelKey = trim($_POST['model_key'] ?? '');
$provider = $_POST['provider'] ?? '';
$label = trim($_POST['label'] ?? '');
$purpose = trim($_POST['purpose'] ?? '');
$costTier = $_POST['cost_tier'] ?? 'standard';

$fail = function (string $message): never {
    set_flash('danger', $message);
    header('Location: /admin/models');
    exit;
};

// OpenRouter ids are namespaced ("vendor/model", optionally ":variant").
if (!preg_match('#^[a-z0-9][a-z0-9._/:-]{1,99}$#i', $modelKey)) {
    $fail('Model key must be 2–100 characters: letters, digits, dots, dashes, slashes, colons, underscores.');
}
if (!in_array($provider, LLM_PROVIDERS, true)) {
    $fail('Unknown provider.');
}
if (!in_array($costTier, ['economy', 'standard', 'premium'], true)) {
    $fail('Unknown cost tier.');
}
if ($label === '') {
    $label = $modelKey;
}
if (isset(ModelRouter::registry()[$modelKey])) {
    $fail("Model '$modelKey' is already in the registry.");
}

// A typo'd model key would break its phase live mid-conversation, so verify
// the id against the provider now — when a key is available to ask with.
$apiKey = ApiCredential::decryptedKeyFor($provider);
$verified = false;
if ($apiKey !== null || $provider === 'openrouter') { // OpenRouter's catalog is public — no key needed

    $http = new Client(['timeout' => 15, 'http_errors' => false]);
    try {
        switch ($provider) {
            case 'anthropic':
                $res = $http->get("https://api.anthropic.com/v1/models/{$modelKey}", [
                    'headers' => ['x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01'],
                ]);
                $status = $res->getStatusCode();
                break;
            case 'gemini':
                $res = $http->get("https://generativelanguage.googleapis.com/v1beta/models/{$modelKey}", [
                    'headers' => ['x-goog-api-key' => $apiKey],
                ]);
                $status = $res->getStatusCode();
                break;
            case 'openai':
                $res = $http->get('https://api.openai.com/v1/models/' . rawurlencode($modelKey), [
                    'headers' => ['Authorization' => "Bearer {$apiKey}"],
                ]);
                $status = $res->getStatusCode();
                break;
            case 'openrouter':
                // No per-model endpoint — check the id against the catalog.
                $res = $http->get('https://openrouter.ai/api/v1/models');
                $ids = array_column(json_decode((string) $res->getBody(), true)['data'] ?? [], 'id');
                $status = $res->getStatusCode() !== 200 ? $res->getStatusCode()
                    : (in_array($modelKey, $ids, true) ? 200 : 404);
                break;
        }
        if ($status === 404) {
            $fail("$provider does not recognise model '$modelKey' — check the exact model id.");
        }
        $verified = $status >= 200 && $status < 300;
    } catch (\Throwable $e) {
        // Network trouble — don't block adding; the phase switch is still explicit.
        if (str_contains($e->getMessage(), 'cURL error 60')) {
            // Same machine quirk as test_connection: AV HTTPS interception.
            $verifyHint = ' (An antivirus may be intercepting HTTPS for this host — see Test connection hint.)';
        }
    }
}

Database::run(
    'INSERT INTO ai_custom_models (model_key, provider, label, purpose, cost_tier) VALUES (?, ?, ?, ?, ?)',
    [$modelKey, $provider, $label, $purpose, $costTier]
);
Database::run(
    'INSERT INTO ai_activity_log (action, model_used, detail) VALUES (?, ?, ?)',
    ['model_added', $modelKey, "Admin added $provider model '$modelKey' to the registry"]
);

set_flash('success', $verified
    ? "$label added — model id confirmed by $provider. Assign it to a phase when ready."
    : "$label added. Could not confirm the model id with $provider (no active key or network issue) — test it on a phase before demo day." . ($verifyHint ?? ''));
header('Location: /admin/models');
exit;
