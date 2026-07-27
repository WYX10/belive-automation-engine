<?php

declare(strict_types=1);

namespace App\Integrations\Meta;

use App\Integrations\Social\MetaGraph;
use App\Models\ApiCredential;
use GuzzleHttp\Client;

/**
 * Resolves WHICH Instagram API this install talks to. Meta ships two, they are
 * not interchangeable, and which one you get depends on how the app was set up
 * in the developer dashboard:
 *
 *   Instagram Login  graph.instagram.com  + an Instagram user token
 *                    permissions named instagram_business_*
 *                    (the "Manage messaging & content on Instagram" use case)
 *
 *   Facebook Login   graph.facebook.com   + the Page token
 *                    permissions named instagram_manage_*
 *                    (an IG account linked to a Page)
 *
 * The rule here: an active 'instagram' credential means Instagram Login and
 * wins. With only 'meta_graph', everything behaves exactly as it did before
 * this class existed — so an install on the older path is untouched.
 *
 * Callers get one resolved bundle instead of each deciding for itself.
 */
final class InstagramApi
{
    /** Instagram Login. Version lives on MetaGraph — one place for all of Meta. */
    public const LOGIN_BASE = MetaGraph::INSTAGRAM_BASE;

    /** Facebook Login — the same base the Page publishers use. */
    public const PAGE_BASE = MetaGraph::BASE;

    public static function usesInstagramLogin(): bool
    {
        return ApiCredential::activeFor('instagram') !== null;
    }

    /** True when Instagram calls can actually be made on either path. */
    public static function isConfigured(): bool
    {
        return self::accountId() !== '';
    }

    /**
     * The IG account id calls are addressed to. Instagram Login carries it on
     * its own credential; the Page path keeps it on meta_graph, where the
     * publishers have always looked for it.
     */
    public static function accountId(): string
    {
        $instagram = self::metaFor('instagram');
        if ($instagram !== null) {
            return (string) ($instagram['ig_user_id'] ?? '');
        }

        return (string) (self::metaFor('meta_graph')['ig_user_id'] ?? '');
    }

    /**
     * Everything an outgoing Instagram request needs, with the Page-token
     * exchange already done where that path applies. Null when Instagram is
     * not configured at all — callers fall back to dry-run.
     *
     * @return array{base:string, token:string, ig_user_id:string, mode:string}|null
     */
    public static function resolve(Client $http): ?array
    {
        $accountId = self::accountId();
        if ($accountId === '') {
            return null;
        }

        if (self::usesInstagramLogin()) {
            $token = ApiCredential::decryptedKeyFor('instagram');

            return $token === null ? null : [
                'base'       => self::LOGIN_BASE,
                'token'      => $token,
                'ig_user_id' => $accountId,
                'mode'       => 'instagram_login',
            ];
        }

        $stored = ApiCredential::decryptedKeyFor('meta_graph');
        if ($stored === null) {
            return null;
        }

        return [
            'base'       => self::PAGE_BASE,
            'token'      => MetaGraph::pageAccessToken($http, $stored, (string) (self::metaFor('meta_graph')['page_id'] ?? '')),
            'ig_user_id' => $accountId,
            'mode'       => 'page_token',
        ];
    }

    private static function metaFor(string $service): ?array
    {
        $cred = ApiCredential::activeFor($service);
        if ($cred === null) {
            return null;
        }

        return json_decode($cred['meta'] ?? '[]', true) ?: [];
    }
}
