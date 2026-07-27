<?php

declare(strict_types=1);

namespace App\Integrations\WhatsApp;

use App\Core\Database;
use App\Models\ApiCredential;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use RuntimeException;

/**
 * Outbound WhatsApp Cloud API sender.
 *
 * DRY-RUN MODE: when no active 'whatsapp' credential is configured, sends are
 * not silently dropped — the exact payload that WOULD go to Meta is written to
 * ai_activity_log as 'wa_dry_run_send' and a simulated id is returned. This
 * exists so local pipeline testing works before go-live; it is clearly
 * labelled in logs and the admin activity view, never passed off as a real
 * delivery. Go-live steps: docs/setup_guide.md.
 */
class WhatsAppClient
{
    private const GRAPH = \App\Integrations\Social\MetaGraph::BASE;

    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client(['timeout' => 30]);
    }

    public function isConfigured(): bool
    {
        $cred = ApiCredential::activeFor('whatsapp');
        if ($cred === null) {
            return false;
        }
        $meta = json_decode($cred['meta'] ?? '[]', true) ?: [];

        return ($meta['phone_number_id'] ?? '') !== '';
    }

    /** @return array{message_id: string, dry_run: bool} */
    public function sendText(string $toWaPhone, string $text): array
    {
        if (!$this->isConfigured()) {
            return $this->dryRun($toWaPhone, ['type' => 'text', 'text' => ['body' => $text]]);
        }

        return $this->post($toWaPhone, [
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $text],
        ]);
    }

    /** Send an image by public URL (room photos). */
    public function sendImage(string $toWaPhone, string $imageUrl, string $caption = ''): array
    {
        // Site-local photo paths (/assets/img/rooms/...) become absolute URLs —
        // Meta fetches media by link, so APP_URL must be the public base.
        if (str_starts_with($imageUrl, '/')) {
            $imageUrl = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8080', '/') . $imageUrl;
        }

        $media = ['link' => $imageUrl] + ($caption !== '' ? ['caption' => $caption] : []);

        if (!$this->isConfigured()) {
            return $this->dryRun($toWaPhone, ['type' => 'image', 'image' => $media]);
        }

        return $this->post($toWaPhone, ['type' => 'image', 'image' => $media]);
    }

    /** @return array{message_id: string, dry_run: bool} */
    private function post(string $to, array $messageFields): array
    {
        $cred = ApiCredential::activeFor('whatsapp');
        $meta = json_decode($cred['meta'] ?? '[]', true) ?: [];
        $phoneNumberId = $meta['phone_number_id']
            ?? throw new RuntimeException('WhatsApp credential missing phone_number_id.');

        $token = ApiCredential::decryptedKeyFor('whatsapp')
            ?? throw new RuntimeException('No active WhatsApp credential.');

        try {
            $response = $this->http->post(self::GRAPH . "/{$phoneNumberId}/messages", [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'messaging_product' => 'whatsapp',
                    'recipient_type'    => 'individual',
                    'to'                => $to,
                ] + $messageFields,
            ]);
        } catch (BadResponseException $e) {
            $body = mb_substr((string) $e->getResponse()->getBody(), 0, 400);
            throw new RuntimeException("WhatsApp send failed ({$e->getResponse()->getStatusCode()}): $body", 0, $e);
        }

        $raw = json_decode((string) $response->getBody(), true) ?? [];

        return [
            'message_id' => $raw['messages'][0]['id'] ?? '',
            'dry_run'    => false,
        ];
    }

    /** @return array{message_id: string, dry_run: bool} */
    private function dryRun(string $to, array $messageFields): array
    {
        Database::run(
            'INSERT INTO ai_activity_log (action, phase, detail) VALUES (?, ?, ?)',
            [
                'wa_dry_run_send',
                'conversion',
                json_encode(
                    ['note' => 'No active WhatsApp credential — payload logged, NOT delivered.', 'to' => $to] + $messageFields,
                    JSON_UNESCAPED_UNICODE
                ),
            ]
        );

        return ['message_id' => 'dry-run-' . bin2hex(random_bytes(6)), 'dry_run' => true];
    }
}
