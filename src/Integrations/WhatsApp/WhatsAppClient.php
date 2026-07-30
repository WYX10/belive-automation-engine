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
 *
 * A REJECTED send is not an exception the caller has to guess about either: it
 * comes back as ['failed' => true, 'error' => <what Meta said>] and is written
 * to ai_activity_log as 'wa_send_failed', so a message that never reached the
 * customer can never look like one that did. Use self::deliveryNote() when
 * writing the interaction row.
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

    /**
     * Reasoning suffix that tells the truth about what happened to a send, so
     * the transcript and the admin view never show an undelivered message as
     * delivered.
     */
    public static function deliveryNote(array $sent): string
    {
        if (!empty($sent['failed'])) {
            return ' ⚠ NOT DELIVERED — WhatsApp rejected the send: ' . ($sent['error'] ?? 'unknown error');
        }

        return !empty($sent['dry_run'])
            ? ' (dry-run: no WhatsApp credential configured — logged, not delivered)'
            : '';
    }

    /** @return array{message_id: string, dry_run: bool, failed: bool, error?: string} */
    public function sendText(string $toWaPhone, string $text): array
    {
        // Meta rejects an empty body outright ("[100] The parameter text.body
        // is required"), so there is nothing to gain from the round trip — and
        // the log entry it produced described the symptom, not the cause.
        // Caught here it names what actually went wrong: nothing was written.
        if (trim($text) === '') {
            return $this->failed(
                $toWaPhone,
                ['type' => 'text', 'text' => ['preview_url' => false, 'body' => '']],
                'Refused to send: the reply text was empty (nothing was generated to say).'
            );
        }

        if (!$this->isConfigured()) {
            return $this->dryRun($toWaPhone, ['type' => 'text', 'text' => ['body' => $text]]);
        }

        return $this->post($toWaPhone, [
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $text],
        ]);
    }

    /**
     * Send an image by public URL (room photos).
     *
     * @return array{message_id: string, dry_run: bool, failed: bool, error?: string}
     */
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

    /** @return array{message_id: string, dry_run: bool, failed: bool, error?: string} */
    private function post(string $to, array $messageFields): array
    {
        // Meta matches on the country-coded form only: '0123456789' is either
        // rejected or accepted-then-never-delivered.
        $to = PhoneNumber::normalize($to);
        if (!PhoneNumber::isValid($to)) {
            return $this->failed($to, $messageFields, "Not a sendable WhatsApp number: '$to'.");
        }

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
            // Meta's rejections are the ordinary way a send fails — most often
            // error 131047, the closed 24-hour customer service window. The
            // caller records the failure in the transcript and carries on;
            // blowing up would have cost the pipeline the whole conversation.
            $raw = json_decode((string) $e->getResponse()->getBody(), true) ?? [];
            $err = $raw['error'] ?? [];
            $reason = trim(sprintf(
                '%s%s %s',
                isset($err['code']) ? "[{$err['code']}] " : '',
                $err['message'] ?? mb_substr((string) $e->getResponse()->getBody(), 0, 300),
                $err['error_data']['details'] ?? ''
            ));

            return $this->failed($to, $messageFields, "HTTP {$e->getResponse()->getStatusCode()} — $reason");
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            return $this->failed($to, $messageFields, 'Network error: ' . $e->getMessage());
        }

        $raw = json_decode((string) $response->getBody(), true) ?? [];

        return [
            'message_id' => $raw['messages'][0]['id'] ?? '',
            'dry_run'    => false,
            'failed'     => false,
        ];
    }

    /**
     * A send Meta refused. Logged with the payload so the reason is readable in
     * the admin activity view, not buried in a server log on Azure.
     *
     * @return array{message_id: string, dry_run: bool, failed: bool, error: string}
     */
    private function failed(string $to, array $messageFields, string $error): array
    {
        Database::run(
            'INSERT INTO ai_activity_log (action, phase, detail) VALUES (?, ?, ?)',
            [
                'wa_send_failed',
                'conversion',
                json_encode(
                    ['error' => $error, 'to' => $to] + $messageFields,
                    JSON_UNESCAPED_UNICODE
                ),
            ]
        );

        return ['message_id' => '', 'dry_run' => false, 'failed' => true, 'error' => $error];
    }

    /** @return array{message_id: string, dry_run: bool, failed: bool} */
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

        return ['message_id' => 'dry-run-' . bin2hex(random_bytes(6)), 'dry_run' => true, 'failed' => false];
    }
}
