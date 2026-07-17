<?php

declare(strict_types=1);

namespace App\Properties;

use App\Core\Database;
use App\Models\Property;
use App\Models\Room;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PropertyManager
{
    public static function addProperty(string $ownerName, array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $location = trim((string) ($input['location'] ?? ''));
        $address = trim((string) ($input['address'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));

        if ($name === '' || $location === '' || $address === '') {
            throw new InvalidArgumentException('Property name, area and address are required.');
        }

        $exists = Database::run(
            'SELECT COUNT(*) FROM properties WHERE owner_name = ? AND name = ? AND location = ?',
            [$ownerName, $name, $location]
        )->fetchColumn() > 0;
        if ($exists) {
            throw new RuntimeException('This property is already on your account.');
        }

        $id = Property::create([
            'owner_name' => $ownerName,
            'name' => $name,
            'location' => $location,
            'address' => $address,
            'description' => $description !== '' ? $description : null,
        ]);

        return Property::find($id) ?? throw new RuntimeException('Property could not be loaded.');
    }

    public static function resubmitRejectedProperty(string $ownerName, int $propertyId, array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $location = trim((string) ($input['location'] ?? ''));
        $address = trim((string) ($input['address'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));

        if ($name === '' || $location === '' || $address === '') {
            throw new InvalidArgumentException('Property name, area and address are required.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $property = Database::run(
                'SELECT * FROM properties WHERE id = ? FOR UPDATE',
                [$propertyId]
            )->fetch();
            if (!$property || $property['owner_name'] !== $ownerName) {
                throw new RuntimeException('That property is not on your account.');
            }
            if ($property['review_status'] !== 'rejected') {
                throw new RuntimeException('Only a rejected property can be corrected and resubmitted.');
            }

            $duplicate = (int) Database::run(
                'SELECT COUNT(*) FROM properties
                 WHERE owner_name = ? AND name = ? AND location = ? AND id <> ?',
                [$ownerName, $name, $location, $propertyId]
            )->fetchColumn();
            if ($duplicate > 0) {
                throw new RuntimeException('This property is already on your account.');
            }

            Property::update($propertyId, [
                'name' => $name,
                'location' => $location,
                'address' => $address,
                'description' => $description !== '' ? $description : null,
                'review_status' => 'pending',
                'review_note' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_version' => (int) $property['review_version'] + 1,
            ]);
            $resubmitted = Property::find($propertyId)
                ?? throw new RuntimeException('Resubmitted property could not be loaded.');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $resubmitted;
    }

    public static function addRoom(string $ownerName, array $input): array
    {
        $propertyId = (int) ($input['property_id'] ?? 0);
        $property = Property::find($propertyId);
        if ($property === null || $property['owner_name'] !== $ownerName) {
            throw new RuntimeException('That property is not on your account.');
        }
        if ($property['review_status'] !== 'approved') {
            throw new RuntimeException('Admin must approve this property before you can add rooms.');
        }

        $roomCode = strtoupper(trim((string) ($input['room_code'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        $roomType = (string) ($input['room_type'] ?? '');
        $status = (string) ($input['status'] ?? 'available');
        $description = trim((string) ($input['description'] ?? ''));
        $availableFrom = trim((string) ($input['available_from'] ?? ''));
        $points = self::validatePoints($input['referral_reward_points'] ?? null);
        $prices = [
            'monthly' => self::positivePrice($input['price_monthly'] ?? null, 'Monthly price'),
            '6_month' => self::positivePrice($input['price_6_month'] ?? null, '6-month price'),
            '12_month' => self::positivePrice($input['price_12_month'] ?? null, '12-month price'),
        ];

        if (!preg_match('/^[A-Z0-9-]{2,20}$/', $roomCode)) {
            throw new InvalidArgumentException('Room code must be 2–20 letters, numbers or hyphens.');
        }
        if (Database::run('SELECT COUNT(*) FROM rooms WHERE room_code = ?', [$roomCode])->fetchColumn() > 0) {
            throw new RuntimeException('That room code is already in use.');
        }
        if ($name === '') {
            throw new InvalidArgumentException('Room name is required.');
        }
        if (!in_array($roomType, ['single', 'middle', 'master'], true)) {
            throw new InvalidArgumentException('Choose a valid room type.');
        }
        if (!in_array($status, ['available', 'occupied', 'reserved'], true)) {
            throw new InvalidArgumentException('Choose a valid room status.');
        }
        if (!($prices['monthly'] >= $prices['6_month'] && $prices['6_month'] >= $prices['12_month'])) {
            throw new InvalidArgumentException('Longer-tenure prices must not be higher than shorter-tenure prices.');
        }
        if ($availableFrom !== '' && !self::isDate($availableFrom)) {
            throw new InvalidArgumentException('Choose a valid available-from date.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $lockedProperty = Database::run(
                'SELECT * FROM properties WHERE id = ? FOR UPDATE',
                [$propertyId]
            )->fetch();
            if (!$lockedProperty || $lockedProperty['owner_name'] !== $ownerName) {
                throw new RuntimeException('That property is not on your account.');
            }
            if ($lockedProperty['review_status'] !== 'approved') {
                throw new RuntimeException('Admin must approve this property before you can add rooms.');
            }
            $property = $lockedProperty;

            $roomId = Room::create([
                'property_id' => $propertyId,
                'room_code' => $roomCode,
                'name' => $name,
                'property_name' => $property['name'],
                'location' => $property['location'],
                'room_type' => $roomType,
                'description' => $description !== '' ? $description : null,
                'status' => $status,
                'deposit_amount' => 0,
                'referral_reward_points' => $points,
                'available_from' => $availableFrom !== '' ? $availableFrom : null,
                'owner_name' => $ownerName,
                'address' => $property['address'],
            ]);

            foreach ($prices as $tenure => $price) {
                Database::run(
                    'INSERT INTO room_pricing (room_id, tenure, price, is_best_value) VALUES (?, ?, ?, ?)',
                    [$roomId, $tenure, $price, $tenure === '12_month' ? 1 : 0]
                );
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return Room::find($roomId) ?? throw new RuntimeException('Room could not be loaded.');
    }

    public static function addRoomForAdmin(int $propertyId, array $input): array
    {
        $property = Property::find($propertyId);
        if ($property === null) {
            throw new RuntimeException('Property not found.');
        }

        return self::addRoom(
            (string) $property['owner_name'],
            array_replace($input, ['property_id' => $propertyId])
        );
    }

    public static function updateRoomForAdmin(int $roomId, array $input): array
    {
        $roomCode = strtoupper(trim((string) ($input['room_code'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        $roomType = (string) ($input['room_type'] ?? '');
        $status = (string) ($input['status'] ?? 'available');
        $description = trim((string) ($input['description'] ?? ''));
        $availableFrom = trim((string) ($input['available_from'] ?? ''));
        $points = self::validatePoints($input['referral_reward_points'] ?? null);
        $prices = [
            'monthly' => self::positivePrice($input['price_monthly'] ?? null, 'Monthly price'),
            '6_month' => self::positivePrice($input['price_6_month'] ?? null, '6-month price'),
            '12_month' => self::positivePrice($input['price_12_month'] ?? null, '12-month price'),
        ];

        if (!preg_match('/^[A-Z0-9-]{2,20}$/', $roomCode)) {
            throw new InvalidArgumentException('Room code must be 2–20 letters, numbers or hyphens.');
        }
        if ((int) Database::run(
            'SELECT COUNT(*) FROM rooms WHERE room_code = ? AND id <> ?',
            [$roomCode, $roomId]
        )->fetchColumn() > 0) {
            throw new RuntimeException('That room code is already in use.');
        }
        if ($name === '') {
            throw new InvalidArgumentException('Room name is required.');
        }
        if (!in_array($roomType, ['single', 'middle', 'master'], true)) {
            throw new InvalidArgumentException('Choose a valid room type.');
        }
        if (!in_array($status, ['available', 'occupied', 'reserved'], true)) {
            throw new InvalidArgumentException('Choose a valid room status.');
        }
        if (!($prices['monthly'] >= $prices['6_month'] && $prices['6_month'] >= $prices['12_month'])) {
            throw new InvalidArgumentException('Longer-tenure prices must not be higher than shorter-tenure prices.');
        }
        if ($availableFrom !== '' && !self::isDate($availableFrom)) {
            throw new InvalidArgumentException('Choose a valid available-from date.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $room = Database::run(
                "SELECT r.*, p.review_status AS property_review_status
                 FROM rooms r
                 JOIN properties p ON p.id = r.property_id
                 WHERE r.id = ? FOR UPDATE",
                [$roomId]
            )->fetch();
            if (!$room) {
                throw new RuntimeException('Room not found.');
            }
            if ($room['property_review_status'] !== 'approved') {
                throw new RuntimeException('Rooms can only be managed under an approved property.');
            }

            Room::update($roomId, [
                'room_code' => $roomCode,
                'name' => $name,
                'room_type' => $roomType,
                'description' => $description !== '' ? $description : null,
                'status' => $status,
                'referral_reward_points' => $points,
                'available_from' => $availableFrom !== '' ? $availableFrom : null,
            ]);
            foreach ($prices as $tenure => $price) {
                Database::run(
                    'INSERT INTO room_pricing (room_id, tenure, price, is_best_value)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE price = VALUES(price), is_best_value = VALUES(is_best_value)',
                    [$roomId, $tenure, $price, $tenure === '12_month' ? 1 : 0]
                );
            }
            $updated = Room::find($roomId) ?? throw new RuntimeException('Updated room could not be loaded.');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $updated;
    }

    public static function updateReferralPoints(string $ownerName, int $roomId, mixed $value): array
    {
        $points = self::validatePoints($value);
        $owned = Database::run(
            "SELECT COUNT(*)
             FROM rooms r
             JOIN properties p ON p.id = r.property_id
             WHERE r.id = ? AND r.owner_name = ? AND p.review_status = 'approved'",
            [$roomId, $ownerName]
        )->fetchColumn() > 0;
        if (!$owned) {
            throw new RuntimeException('That room is not on an approved property in your account.');
        }

        Room::update($roomId, ['referral_reward_points' => $points]);
        return Room::find($roomId) ?? throw new RuntimeException('Room could not be loaded.');
    }

    private static function validatePoints(mixed $value): int
    {
        $raw = is_string($value) ? trim($value) : $value;
        if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('Referral points must be a whole number.');
        }
        $points = (int) $raw;
        if ($points < 0 || $points > 1000) {
            throw new InvalidArgumentException('Referral points must be between 0 and 1,000.');
        }
        return $points;
    }

    private static function positivePrice(mixed $value, string $label): float
    {
        if (!is_numeric($value) || (float) $value <= 0) {
            throw new InvalidArgumentException("$label must be greater than zero.");
        }
        return round((float) $value, 2);
    }

    private static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
