<?php

declare(strict_types=1);

namespace App\Integrations\Meta;

use App\Core\Database;
use App\Integrations\Social\MetaGraph;
use App\Models\ApiCredential;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use RuntimeException;

/**
 * Outbound Meta conversation transport — the three calls that turn a comment
 * on a post into a WhatsApp chat:
 *
 *   Facebook Page                          Instagram
 *   -------------------------------------- ---------------------------------
 *   public   POST /{comment-id}/comments    POST /{comment-id}/replies
 *   private  POST /{comment-id}/private_replies
 *                                           POST /{ig-user-id}/messages
 *                                           with recipient.comment_id
 *   DM       POST /{page-id}/messages       POST /{ig-user-id}/messages
 *            with recipient.id              with recipient.id
 *
 * Meta's rules this class exists to respect:
 *  - ONE private reply per comment, within 7 days of it being posted. The
 *    caller enforces once-only through social_replies (Meta returns an error
 *    on the second attempt, and an error is a worse way to find out).
 *  - A direct message may be answered inside a 24-hour window.
 *  - Everything runs on a PAGE access token, even Instagram — MetaGraph
 *    derives it, so a user or system-user token in the credential works too.
 *
 * DRY-RUN MODE mirrors WhatsAppClient: with no active 'meta_graph' credential
 * the exact payload is written to ai_activity_log as 'social_dry_run_reply'
 * and a simulated id comes back. Clearly labelled, never passed off as a real
 * delivery — so the whole flow is demoable before App Review is granted.
 *
 * Not final: the tests substitute a capturing subclass.
 */
class MetaMessenger
{
    private const GRAPH = 'https://graph.facebook.com/v20.0';

    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client(['timeout' => 30]);
    }

    /** True when this platform can actually send (credential + account id). */
    public function isConfigured(string $platform): bool
    {
        return $this->accountId($platform) !== ''
            && ApiCredential::activeFor('meta_graph') !== null;
    }

    /**
     * Our own Page/IG account commenting on our own post. Without this guard
     * the auto-reply we just posted arrives back as a new comment webhook and
     * Eve answers herself, forever.
     */
    public function isOwnAccount(string $platform, string $userId): bool
    {
        if ($userId === '') {
            return false;
        }
        $meta = $this->credentialMeta();

        return $userId === (string) ($meta['page_id'] ?? '')
            || $userId === (string) ($meta['ig_user_id'] ?? '');
    }

    /**
     * Public reply under the comment — the social proof half. On Instagram a
     * reply must target a TOP-LEVEL comment, so the caller passes the parent
     * id when the webhook carried one.
     *
     * @return array{id: string, dry_run: bool}
     */
    public function replyToComment(string $platform, string $commentId, string $text): array
    {
        $path = $platform === 'instagram' ? "/{$commentId}/replies" : "/{$commentId}/comments";

        return $this->send($platform, $path, ['message' => $text]);
    }

    /**
     * The DM triggered by a comment. This is the one that carries the wa.me
     * link, and the one Meta allows exactly once per comment.
     *
     * @return array{id: string, dry_run: bool}
     */
    public function privateReplyToComment(string $platform, string $commentId, string $text): array
    {
        if ($platform === 'instagram') {
            return $this->send('instagram', '/' . $this->accountId('instagram') . '/messages', [
                'recipient' => ['comment_id' => $commentId],
                'message'   => ['text' => $text],
            ]);
        }

        return $this->send('facebook', "/{$commentId}/private_replies", ['message' => $text]);
    }

    /**
     * Reply to someone who messaged the Page/account directly.
     *
     * @return array{id: string, dry_run: bool}
     */
    public function sendDirectMessage(string $platform, string $recipientId, string $text): array
    {
        $payload = [
            'recipient' => ['id' => $recipientId],
            'message'   => ['text' => $text],
        ];
        if ($platform === 'facebook') {
            // Answering an inbound message inside the 24-hour window.
            $payload['messaging_type'] = 'RESPONSE';
        }

        return $this->send($platform, '/' . $this->accountId($platform) . '/messages', $payload);
    }

    /** @return array{id: string, dry_run: bool} */
    private function send(string $platform, string $path, array $payload): array
    {
        if (!$this->isConfigured($platform)) {
            return $this->dryRun($platform, $path, $payload);
        }

        $meta = $this->credentialMeta();
        $stored = ApiCredential::decryptedKeyFor('meta_graph')
            ?? throw new RuntimeException('No active Meta Graph credential.');
        $token = MetaGraph::pageAccessToken($this->http, $stored, (string) ($meta['page_id'] ?? ''));

        try {
            $response = $this->http->post(self::GRAPH . $path, [
                'headers' => ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json'],
                'json'    => $payload,
            ]);
        } catch (BadResponseException $e) {
            $body = mb_substr((string) $e->getResponse()->getBody(), 0, 400);
            throw new RuntimeException(
                ucfirst($platform) . " reply failed ({$e->getResponse()->getStatusCode()}): $body",
                0,
                $e
            );
        }

        $raw = json_decode((string) $response->getBody(), true) ?? [];

        return [
            'id'      => (string) ($raw['message_id'] ?? $raw['id'] ?? ''),
            'dry_run' => false,
        ];
    }

    /** page_id for Facebook, ig_user_id for Instagram — both live on the credential. */
    private function accountId(string $platform): string
    {
        $meta = $this->credentialMeta();

        return (string) ($platform === 'instagram' ? ($meta['ig_user_id'] ?? '') : ($meta['page_id'] ?? ''));
    }

    private function credentialMeta(): array
    {
        $cred = ApiCredential::activeFor('meta_graph');
        if ($cred === null) {
            return [];
        }

        return json_decode($cred['meta'] ?? '[]', true) ?: [];
    }

    /** @return array{id: string, dry_run: bool} */
    private function dryRun(string $platform, string $path, array $payload): array
    {
        Database::run(
            'INSERT INTO ai_activity_log (action, phase, detail) VALUES (?, ?, ?)',
            [
                'social_dry_run_reply',
                'lead_gen',
                json_encode(
                    [
                        'note'     => 'No active meta_graph credential (or missing page_id/ig_user_id) — payload logged, NOT delivered.',
                        'platform' => $platform,
                        'endpoint' => $path,
                        'payload'  => $payload,
                    ],
                    JSON_UNESCAPED_UNICODE
                ),
            ]
        );

        return ['id' => 'sim-' . substr($platform, 0, 2) . '-' . bin2hex(random_bytes(5)), 'dry_run' => true];
    }
}
