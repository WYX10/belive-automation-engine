<?php

declare(strict_types=1);

namespace App\Integrations\Meta;

/**
 * Normalizes Meta Graph webhook payloads for FB Page / Instagram comment
 * events into a flat shape the lead pipeline consumes. Transport-only.
 */
final class CommentWebhookParser
{
    /**
     * @return array<int, array{platform:string, user_id:string, user_name:?string, text:string, comment_id:string}>
     */
    public static function parse(array $payload): array
    {
        $comments = [];
        $platform = ($payload['object'] ?? '') === 'instagram' ? 'instagram' : 'facebook';

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $field = $change['field'] ?? '';
                if (!in_array($field, ['feed', 'comments', 'mention'], true)) {
                    continue;
                }

                $value = $change['value'] ?? [];

                // FB Page feed comments arrive as item=comment; IG comment
                // webhooks put text/id at the top of value.
                if ($field === 'feed' && ($value['item'] ?? '') !== 'comment') {
                    continue;
                }

                $text = $value['message'] ?? $value['text'] ?? '';
                $userId = $value['from']['id'] ?? '';
                if ($text === '' || $userId === '') {
                    continue;
                }

                $comments[] = [
                    'platform'   => $platform,
                    'user_id'    => $userId,
                    'user_name'  => $value['from']['name'] ?? $value['from']['username'] ?? null,
                    'text'       => $text,
                    'comment_id' => $value['comment_id'] ?? $value['id'] ?? '',
                ];
            }
        }

        return $comments;
    }
}
