<?php

declare(strict_types=1);

namespace App\Properties;

use App\Core\Database;
use App\Models\Room;
use RuntimeException;
use Throwable;

final class RoomPhotoManager
{
    private const UPLOAD_DIR = '/assets/img/uploads/rooms';
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const MAX_PIXELS = 25_000_000;
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Store one room gallery image. Passing an owner name enforces ownership;
     * admin callers pass null. The mover hook exists for deterministic CLI tests.
     *
     * @param array<string, mixed> $file one entry from $_FILES
     * @return array{id:int, room_id:int, image_path:string, sort_order:int}
     */
    public static function addUpload(
        int $roomId,
        array $file,
        ?string $ownerName = null,
        ?callable $mover = null
    ): array {
        if ($ownerName === null) {
            $room = Room::find($roomId);
            if ($room === null) {
                throw new RuntimeException('Room not found.');
            }
        } else {
            $room = Database::run(
                "SELECT r.*
                 FROM rooms r
                 JOIN properties p ON p.id = r.property_id
                 WHERE r.id = ? AND r.owner_name = ? AND p.review_status = 'approved'
                 LIMIT 1",
                [$roomId, $ownerName]
            )->fetch();
            if (!$room) {
                throw new RuntimeException('That room is not on an approved property in your account.');
            }
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadErrorMessage($error));
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_file($tmpPath)) {
            throw new RuntimeException('The uploaded photo could not be read. Choose it again.');
        }
        $size = filesize($tmpPath);
        if ($size === false || $size < 1) {
            throw new RuntimeException('The uploaded photo is empty.');
        }
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('Room photos must be 5 MB or smaller.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
        $extension = self::MIME_EXTENSIONS[$mime ?: ''] ?? null;
        if ($extension === null) {
            throw new RuntimeException('Only JPG, PNG or WebP room photos are accepted.');
        }
        $dimensions = getimagesize($tmpPath);
        if ($dimensions === false || ($dimensions[0] * $dimensions[1]) > self::MAX_PIXELS) {
            throw new RuntimeException('The room photo is invalid or its dimensions are too large.');
        }

        if ($mover === null && !is_uploaded_file($tmpPath)) {
            throw new RuntimeException('The room photo was not received as a valid upload.');
        }

        $stagingPath = sys_get_temp_dir() . '/belive_room_' . bin2hex(random_bytes(12)) . '.upload';
        $staged = $mover !== null
            ? (bool) $mover($tmpPath, $stagingPath)
            : move_uploaded_file($tmpPath, $stagingPath);
        if (!$staged) {
            throw new RuntimeException('The room photo could not be staged safely. Please try again.');
        }

        try {
            $bytes = file_get_contents($stagingPath);
            $decoded = $bytes !== false ? @imagecreatefromstring($bytes) : false;
            if ($decoded === false) {
                throw new RuntimeException('The room photo is incomplete or cannot be decoded.');
            }
            if ((imagesx($decoded) * imagesy($decoded)) > self::MAX_PIXELS) {
                imagedestroy($decoded);
                throw new RuntimeException('The room photo dimensions are too large.');
            }

            $directory = APP_ROOT . '/public' . self::UPLOAD_DIR;
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                imagedestroy($decoded);
                throw new RuntimeException('The room photo folder could not be created.');
            }

            $filename = sprintf(
                'room_%d_%s_%s.%s',
                $roomId,
                date('Ymd_His'),
                bin2hex(random_bytes(6)),
                $extension
            );
            $absolutePath = $directory . '/' . $filename;
            $encoded = match ($extension) {
                'jpg' => imagejpeg($decoded, $absolutePath, 88),
                'png' => imagepng($decoded, $absolutePath, 6),
                'webp' => imagewebp($decoded, $absolutePath, 85),
            };
            imagedestroy($decoded);
            if (!$encoded) {
                @unlink($absolutePath);
                throw new RuntimeException('The room photo could not be normalized and stored.');
            }
        } finally {
            if (is_file($stagingPath) && !unlink($stagingPath)) {
                error_log('[room photo] Could not remove staging file ' . basename($stagingPath));
            }
        }

        try {
            $sortOrder = (int) Database::run(
                'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM room_images WHERE room_id = ?',
                [$roomId]
            )->fetchColumn();
            Database::run(
                'INSERT INTO room_images (room_id, image_path, sort_order) VALUES (?, ?, ?)',
                [$roomId, self::UPLOAD_DIR . '/' . $filename, $sortOrder]
            );
            $id = (int) Database::pdo()->lastInsertId();
        } catch (Throwable $e) {
            if (is_file($absolutePath) && !unlink($absolutePath)) {
                error_log('[room photo] Could not remove orphaned file ' . basename($absolutePath));
            }
            throw $e;
        }

        return [
            'id' => $id,
            'room_id' => $roomId,
            'image_path' => self::UPLOAD_DIR . '/' . $filename,
            'sort_order' => $sortOrder,
        ];
    }

    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Room photos must be 5 MB or smaller.',
            UPLOAD_ERR_NO_FILE => 'Choose a room photo to upload.',
            UPLOAD_ERR_PARTIAL => 'The room photo upload was interrupted. Please try again.',
            default => 'The room photo upload failed. Please try again.',
        };
    }
}
