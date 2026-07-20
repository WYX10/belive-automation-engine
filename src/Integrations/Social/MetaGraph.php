<?php

declare(strict_types=1);

namespace App\Integrations\Social;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;

/**
 * Shared Meta Graph helpers for the Facebook/Instagram publishers.
 */
final class MetaGraph
{
    public const BASE = 'https://graph.facebook.com/v20.0';

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
