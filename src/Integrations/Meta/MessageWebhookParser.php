<?php

declare(strict_types=1);

namespace App\Integrations\Meta;

/**
 * Normalizes Meta's Messenger/Instagram DIRECT MESSAGE webhooks (the
 * entry[].messaging[] array) into the same flat shape the comment parser
 * produces, so the lead pipeline treats a DM and a comment identically.
 * Transport-only — no policy, no sending.
 *
 * Deliberately dropped here:
 *  - echoes (message.is_echo): our own outbound DM comes straight back as an
 *    inbound event. Answering it would loop.
 *  - delivery / read receipts and reactions: no message text to act on.
 *  - anything from our own Page or IG account id, for the same loop reason.
 */
final class MessageWebhookParser
{
    /**
     * @return array<int, array{platform:string, user_id:string, user_name:?string, text:string, message_id:string}>
     */
    public static function parse(array $payload): array
    {
        $messages = [];
        $platform = ($payload['object'] ?? '') === 'instagram' ? 'instagram' : 'facebook';

        foreach ($payload['entry'] ?? [] as $entry) {
            // The Page/IG account that received the message — never a sender.
            $accountId = (string) ($entry['id'] ?? '');

            foreach ($entry['messaging'] ?? [] as $event) {
                $message = $event['message'] ?? null;
                if (!is_array($message) || ($message['is_echo'] ?? false)) {
                    continue;
                }

                $text = trim((string) ($message['text'] ?? ''));
                $senderId = (string) ($event['sender']['id'] ?? '');
                if ($text === '' || $senderId === '' || $senderId === $accountId) {
                    continue;
                }

                $messages[] = [
                    'platform'   => $platform,
                    'user_id'    => $senderId,
                    'user_name'  => $event['sender']['name'] ?? $event['sender']['username'] ?? null,
                    'text'       => $text,
                    'message_id' => (string) ($message['mid'] ?? ''),
                ];
            }
        }

        return $messages;
    }
}
