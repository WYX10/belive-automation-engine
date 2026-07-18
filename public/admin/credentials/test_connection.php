<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Models\ApiCredential;
use GuzzleHttp\Client;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();
Auth::requireCsrf();

$id = (int) ($_POST['id'] ?? 0);
$activateOnSuccess = ($_POST['activate_on_success'] ?? '') === '1';

$row = ApiCredential::find($id);
if ($row === null) {
    set_flash('danger', 'Credential not found.');
    header('Location: /admin/credentials');
    exit;
}

$key = ApiCredential::decryptedKey($id);
$meta = json_decode($row['meta'] ?? '[]', true) ?: [];
$http = new Client(['timeout' => 15, 'http_errors' => false]);

/**
 * Minimal, cheap "does this key work" ping per provider — no tokens consumed
 * beyond a metadata request.
 */
[$ok, $detail] = (function () use ($row, $key, $meta, $http): array {
    try {
        switch ($row['service']) {
            case 'anthropic':
                $res = $http->get('https://api.anthropic.com/v1/models', [
                    'headers' => ['x-api-key' => $key, 'anthropic-version' => '2023-06-01'],
                ]);
                break;

            case 'gemini':
                $res = $http->get('https://generativelanguage.googleapis.com/v1beta/models', [
                    'headers' => ['x-goog-api-key' => $key],
                ]);
                break;

            case 'whatsapp':
                $phoneId = $meta['phone_number_id'] ?? '';
                if ($phoneId === '') {
                    return [false, 'Missing phone_number_id — edit the credential and add it.'];
                }
                $res = $http->get("https://graph.facebook.com/v20.0/{$phoneId}", [
                    'headers' => ['Authorization' => "Bearer {$key}"],
                ]);
                break;

            case 'openai':
                $res = $http->get('https://api.openai.com/v1/models', [
                    'headers' => ['Authorization' => "Bearer {$key}"],
                ]);
                break;

            case 'openrouter':
                $res = $http->get('https://openrouter.ai/api/v1/key', [
                    'headers' => ['Authorization' => "Bearer {$key}"],
                ]);
                break;

            case 'meta_graph':
                $res = $http->get('https://graph.facebook.com/v20.0/me', [
                    'headers' => ['Authorization' => "Bearer {$key}"],
                ]);
                break;

            default:
                return [false, "No test defined for service '{$row['service']}'."];
        }
    } catch (\Throwable $e) {
        $detail = 'Network error: ' . $e->getMessage();
        // cURL error 60 on this machine usually means antivirus HTTPS
        // interception (e.g. Avast Web Shield re-signing certificates) —
        // no CA bundle can fix that; the AV setting must be changed.
        if (str_contains($e->getMessage(), 'cURL error 60')) {
            $detail .= ' — HINT: an antivirus (e.g. Avast Web Shield) may be intercepting HTTPS.'
                . ' Disable its "HTTPS scanning" or add an exception for this API host, then retest.';
        }
        return [false, $detail];
    }

    $status = $res->getStatusCode();
    if ($status >= 200 && $status < 300) {
        return [true, "HTTP $status — key accepted by provider."];
    }

    $body = mb_substr((string) $res->getBody(), 0, 300);
    return [false, "HTTP $status — $body"];
})();

ApiCredential::recordTest($id, $ok, $detail);

if ($ok && $activateOnSuccess) {
    ApiCredential::activate($id);
    set_flash('success', "Connection OK — credential activated. ($detail)");
} elseif ($ok) {
    set_flash('success', "Connection OK. ($detail)");
} else {
    set_flash('danger', "Connection failed: $detail");
}

header('Location: /admin/credentials');
exit;
