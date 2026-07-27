<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * social_replies — the record of every FB/IG event Eve has answered.
 *
 * Two invariants live here:
 *  - an event is answered AT MOST ONCE (claim() is the gate: Meta redelivers
 *    webhooks, and allows only one private reply per comment anyway),
 *  - a ref token is redeemed AT MOST ONCE (claimToken()), so two people
 *    pasting the same link cannot both inherit one social lead's history.
 */
final class SocialReply extends BaseModel
{
    protected const TABLE = 'social_replies';

    /**
     * First-sight gate. Returns the new row id, or null when this platform +
     * object_id has already been handled (redelivery, or a second webhook for
     * the same comment).
     */
    public static function claim(
        string $platform,
        string $objectId,
        string $eventType,
        string $senderId,
        ?string $senderName,
        ?int $leadId
    ): ?int {
        $inserted = Database::run(
            'INSERT IGNORE INTO social_replies (platform, object_id, event_type, sender_id, sender_name, lead_id)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$platform, $objectId, $eventType, $senderId, $senderName, $leadId]
        )->rowCount() === 1;

        return $inserted ? (int) Database::pdo()->lastInsertId() : null;
    }

    /**
     * Mint the single-use attribution token that rides along in the wa.me
     * link. Retries on the (vanishingly unlikely) unique-key collision rather
     * than handing out a token that failed to store.
     */
    public static function attachToken(int $id): ?string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = 'BL' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            try {
                self::update($id, ['ref_token' => $token]);

                return $token;
            } catch (\PDOException) {
                continue; // collision — draw another
            }
        }

        return null;
    }

    public static function findByToken(string $token): ?array
    {
        return self::first(['ref_token' => strtoupper($token)]);
    }

    /**
     * Redeem a token for a WhatsApp lead. Returns false when the token is
     * unknown or already spent, so the caller can tell "attributed" from
     * "someone forwarded the link to a friend".
     */
    public static function claimToken(int $id, int $whatsappLeadId): bool
    {
        return Database::run(
            'UPDATE social_replies SET claimed_by_lead_id = ?, claimed_at = NOW()
             WHERE id = ? AND claimed_at IS NULL',
            [$whatsappLeadId, $id]
        )->rowCount() === 1;
    }

    public static function recordOutcome(int $id, string $publicStatus, string $privateStatus, ?string $error): void
    {
        self::update($id, [
            'public_reply'  => $publicStatus,
            'private_reply' => $privateStatus,
            'error'         => $error === null ? null : mb_substr($error, 0, 500),
        ]);
    }

    /** Recent activity for the admin panel, with the lead each event created. */
    public static function recent(int $limit = 50): array
    {
        return Database::run(
            'SELECT s.*, l.name AS lead_name, l.wa_phone AS lead_handle,
                    c.name AS claimed_name, c.wa_phone AS claimed_phone
             FROM social_replies s
             LEFT JOIN leads l ON l.id = s.lead_id
             LEFT JOIN leads c ON c.id = s.claimed_by_lead_id
             ORDER BY s.id DESC LIMIT ' . max(1, $limit)
        )->fetchAll();
    }

    /** @return array{answered:int, delivered:int, converted:int} */
    public static function counts(): array
    {
        $row = Database::run(
            "SELECT COUNT(*) AS answered,
                    SUM(private_reply = 'sent') AS delivered,
                    SUM(claimed_at IS NOT NULL) AS converted
             FROM social_replies"
        )->fetch() ?: [];

        return [
            'answered'  => (int) ($row['answered'] ?? 0),
            'delivered' => (int) ($row['delivered'] ?? 0),
            'converted' => (int) ($row['converted'] ?? 0),
        ];
    }
}
