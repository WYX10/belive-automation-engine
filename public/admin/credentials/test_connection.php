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
                $res = $http->get(\App\Integrations\Social\MetaGraph::BASE . "/{$phoneId}", [
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
                // Prefer verifying against the Page when a page_id is stored —
                // publishing needs a Page token, and /me alone can't tell.
                $pageId = $meta['page_id'] ?? '';
                $base = \App\Integrations\Social\MetaGraph::BASE;
                $res = $http->get(
                    $base . '/' . ($pageId !== '' ? "{$pageId}?fields=id,name" : 'me'),
                    ['headers' => ['Authorization' => "Bearer {$key}"]]
                );

                // Reading the Page needs pages_read_engagement, which the
                // auto-reply itself does NOT — comments arrive by webhook.
                // Refusing to activate a token that would work fine is worse
                // than not being able to name the Page, so fall back to /me
                // and say plainly what could not be confirmed.
                if ($res->getStatusCode() === 400 && $pageId !== '') {
                    $me = $http->get($base . '/me', ['headers' => ['Authorization' => "Bearer {$key}"]]);
                    if ($me->getStatusCode() < 300) {
                        return [true, 'Token accepted, but the Page could not be read'
                            . ' (needs pages_read_engagement) — so it is unconfirmed that this token'
                            . " belongs to Page $pageId. Messaging and webhooks do not require it."];
                    }
                }
                break;

            case 'instagram':
                // Instagram Login tokens are rejected by graph.facebook.com,
                // so this has to be verified where it will actually be used.
                $res = $http->get(
                    \App\Integrations\Meta\InstagramApi::LOGIN_BASE . '/me?fields=id,username',
                    ['headers' => ['Authorization' => "Bearer {$key}"]]
                );
                break;

            case 'tiktok':
                $res = $http->get('https://open.tiktokapis.com/v2/user/info/?fields=open_id,display_name', [
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
