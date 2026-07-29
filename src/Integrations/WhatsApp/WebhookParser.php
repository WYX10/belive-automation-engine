<?php

declare(strict_types=1);

namespace App\Integrations\WhatsApp;

/**
 * Normalizes Meta's WhatsApp Cloud API webhook payloads into a flat
 * ['wa_phone', 'name', 'text', 'message_id', 'timestamp'] shape the
 * conversation pipeline consumes. Transport-only — no business logic.
 */
final class WebhookParser
{
    /**
     * @return array<int, array{wa_phone:string, name:?string, text:string, message_id:string, timestamp:int}>
     */
    public static function parseInboundMessages(array $payload): array
    {
        $messages = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? '') !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? [];

                // Contact display names, keyed by wa_id.
                $names = [];
                foreach ($value['contacts'] ?? [] as $contact) {
                    $names[$contact['wa_id'] ?? ''] = $contact['profile']['name'] ?? null;
                }

                foreach ($value['messages'] ?? [] as $message) {
                    // Text messages only for competition scope; other types
                    // (image, audio, ...) surface as a readable placeholder.
                    $text = match ($message['type'] ?? '') {
                        'text'        => $message['text']['body'] ?? '',
                        'button'      => $message['button']['text'] ?? '',
                        'interactive' => $message['interactive']['button_reply']['title']
                            ?? $message['interactive']['list_reply']['title'] ?? '',
                        default       => '[' . ($message['type'] ?? 'unsupported') . ' message]',
                    };

                    $from = $message['from'] ?? '';
                    if ($from === '' || $text === '') {
                        continue;
                    }

                    $messages[] = [
                        'wa_phone'   => $from,
                        'name'       => $names[$from] ?? null,
                        'text'       => $text,
                        'message_id' => $message['id'] ?? '',
                        'timestamp'  => (int) ($message['timestamp'] ?? time()),
                    ];
                }
            }
        }

        return $messages;
    }

    /**
     * Deliveries Meta gave up on. A send can return HTTP 200 with a message id
     * and still never arrive — a closed 24-hour window, a number that is not on
     * WhatsApp — and the only notice of that is this status callback. Dropping
     * it (as we used to) leaves a message looking sent forever.
     *
     * @return array<int, array{message_id:string, recipient:string, code:int, title:string, details:string}>
     */
    public static function parseFailedStatuses(array $payload): array
    {
        $failures = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach (($change['value'] ?? [])['statuses'] ?? [] as $status) {
                    if (($status['status'] ?? '') !== 'failed') {
                        continue;
                    }

                    $error = ($status['errors'] ?? [])[0] ?? [];
                    $failures[] = [
                        'message_id' => (string) ($status['id'] ?? ''),
                        'recipient'  => (string) ($status['recipient_id'] ?? ''),
                        'code'       => (int) ($error['code'] ?? 0),
                        'title'      => (string) ($error['title'] ?? 'Unknown delivery failure'),
                        'details'    => (string) ($error['error_data']['details'] ?? $error['message'] ?? ''),
                    ];
                }
            }
        }

        return $failures;
    }

    /** True when the payload is a WhatsApp status update (sent/delivered/read). */
    public static function isStatusOnly(array $payload): bool
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                if (!empty($value['messages'])) {
                    return false;
                }
            }
        }

        return true;
    }
}
