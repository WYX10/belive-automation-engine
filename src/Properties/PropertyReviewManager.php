<?php

declare(strict_types=1);

namespace App\Properties;

use App\Core\Database;
use App\Models\Property;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PropertyReviewManager
{
    /** @return array<int, array<string, mixed>> */
    public static function forReview(string $status = 'pending'): array
    {
        $allowed = ['pending', 'approved', 'rejected', 'all'];
        if (!in_array($status, $allowed, true)) {
            $status = 'pending';
        }

        $where = $status === 'all' ? '' : 'WHERE p.review_status = ?';
        $params = $status === 'all' ? [] : [$status];

        return Database::run(
            "SELECT p.*, COUNT(r.id) AS room_count
             FROM properties p
             LEFT JOIN rooms r ON r.property_id = p.id
             $where
             GROUP BY p.id
             ORDER BY CASE p.review_status WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END,
                      p.created_at DESC, p.id DESC",
            $params
        )->fetchAll();
    }

    /** @return array{pending:int, approved:int, rejected:int, all:int} */
    public static function counts(): array
    {
        $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
        foreach (Database::run(
            'SELECT review_status, COUNT(*) AS total FROM properties GROUP BY review_status'
        )->fetchAll() as $row) {
            $status = (string) $row['review_status'];
            $counts[$status] = (int) $row['total'];
            $counts['all'] += (int) $row['total'];
        }
        return $counts;
    }

    public static function review(
        int $propertyId,
        string $decision,
        string $reviewer,
        string $note,
        int $expectedVersion
    ): array {
        $decision = strtolower(trim($decision));
        $reviewer = trim($reviewer);
        $note = trim($note);

        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Choose approve or reject.');
        }
        if ($reviewer === '') {
            throw new InvalidArgumentException('The admin reviewer is required.');
        }
        if ($decision === 'rejected' && $note === '') {
            throw new InvalidArgumentException('Add a rejection reason so the owner knows what to correct.');
        }
        if (mb_strlen($note) > 500) {
            throw new InvalidArgumentException('The review note must be 500 characters or fewer.');
        }
        if ($expectedVersion < 1) {
            throw new InvalidArgumentException('The property review version is missing. Reload the review queue.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $property = Database::run(
                'SELECT * FROM properties WHERE id = ? FOR UPDATE',
                [$propertyId]
            )->fetch();
            if (!$property) {
                throw new RuntimeException('Property not found.');
            }
            if ((int) $property['review_version'] !== $expectedVersion) {
                throw new RuntimeException('This property was reviewed or resubmitted after you opened the page. Reload before deciding.');
            }

            if ($decision === 'rejected' && $property['review_status'] === 'approved') {
                $roomCount = (int) Database::run(
                    'SELECT COUNT(*) FROM rooms WHERE property_id = ?',
                    [$propertyId]
                )->fetchColumn();
                if ($roomCount > 0) {
                    throw new RuntimeException('This approved property already has rooms and cannot be rejected from this queue.');
                }
            }

            Property::update($propertyId, [
                'review_status' => $decision,
                'review_note' => $note !== '' ? $note : null,
                'reviewed_by' => $reviewer,
                'reviewed_at' => date('Y-m-d H:i:s'),
                'review_version' => $expectedVersion + 1,
            ]);
            $reviewedProperty = Property::find($propertyId)
                ?? throw new RuntimeException('Reviewed property could not be loaded.');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $reviewedProperty;
    }
}
