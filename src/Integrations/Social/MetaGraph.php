<?php

declare(strict_types=1);

namespace App\Integrations\Social;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;

/**
 * Shared Meta Graph helpers and the single source of truth for the API
 * version every Meta integration calls — WhatsApp, the publishers, and the
 * social auto-reply alike.
 *
 * Keep this current. Meta deprecates a version roughly every two years and
 * then silently auto-upgrades calls for a grace period, announcing it only in
 * a response header:
 *
 *   x-ad-api-version-warning: The call has been auto-upgraded to v25.0
 *                             as v20.0 has been deprecated.
 *
 * Nothing breaks while that lasts, which is exactly why it goes unnoticed
 * until the grace period ends and live calls start failing instead.
 */
final class MetaGraph
{
    public const VERSION = 'v25.0';

    public const BASE = 'https://graph.facebook.com/' . self::VERSION;

    /** Instagram Login talks to its own host, same version numbering. */
    public const INSTAGRAM_BASE = 'https://graph.instagram.com/' . self::VERSION;

    /**
     * Publishing to a Page (feed/photos) and to a Page-linked IG account must
     * use a PAGE access token, even when the stored credential is a
     * user/system-user token — otherwise Meta returns the misleading legacy
     * error "(#200) The permission(s) publish_actions ... deprecated".
     *
     * This exchanges the stored token for the Page token via
     * GET /{page_id}?fields=access_token. If the stored token is ALREADY a
     * Page token (the exchange returns nothing or errors), it is used as-is,
     * so both credential styles work.
     */
    public static function pageAccessToken(Client $http, string $storedToken, string $pageId): string
    {
        if ($pageId === '') {
            return $storedToken;
        }

        try {
            $res = $http->get(self::BASE . "/{$pageId}?fields=access_token", [
                'headers' => ['Authorization' => "Bearer {$storedToken}"],
            ]);
            $data = json_decode((string) $res->getBody(), true) ?? [];
            if (!empty($data['access_token'])) {
                return (string) $data['access_token'];
            }
        } catch (BadResponseException) {
            // Stored token is likely already a Page token — fall through.
        }

        return $storedToken;
    }
}
