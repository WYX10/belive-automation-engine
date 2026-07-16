<?php

declare(strict_types=1);

namespace App\Verification;

use App\AI\Memory\EpisodicLogger;
use App\Models\MoveInLog;
use RuntimeException;

/**
 * Move-In Condition Log — timestamped photos of a room's condition before a
 * tenant moves in; dispute-prevention evidence both sides can see.
 */
final class MoveInLogger
{
    private const UPLOAD_DIR = '/assets/img/uploads';

    /** Add an entry from a photo URL (demo-friendly) or an uploaded file. */
    public static function addByUrl(int $roomId, ?int $leadId, string $photoUrl, string $caption, string $uploadedBy = 'admin'): int
    {
        if (!filter_var($photoUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Photo URL is not valid.');
        }

        return self::insert($roomId, $leadId, $photoUrl, $caption, $uploadedBy);
    }

    /** @param array $file one entry from $_FILES */
    public static function addUpload(int $roomId, ?int $leadId, array $file, string $caption, string $uploadedBy = 'admin'): int
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed (error ' . ($file['error'] ?? '?') . ').');
        }

        $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new RuntimeException('Only jpg/png/webp photos are accepted.');
        }
        if (!str_starts_with((string) mime_content_type($file['tmp_name']), 'image/')) {
            throw new RuntimeException('File is not an image.');
        }

        $dir = APP_ROOT . '/public' . self::UPLOAD_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $name = 'movein_' . $roomId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], "$dir/$name")) {
            throw new RuntimeException('Could not store the uploaded photo.');
        }

        return self::insert($roomId, $leadId, self::UPLOAD_DIR . "/$name", $caption, $uploadedBy);
    }

    private static function insert(int $roomId, ?int $leadId, string $path, string $caption, string $uploadedBy): int
    {
        $id = MoveInLog::create([
            'room_id'     => $roomId,
            'lead_id'     => $leadId,
            'photo_path'  => $path,
            'caption'     => mb_substr($caption, 0, 255),
            'taken_at'    => date('Y-m-d H:i:s'),
            'uploaded_by' => in_array($uploadedBy, ['admin', 'owner'], true) ? $uploadedBy : 'admin',
        ]);

        EpisodicLogger::activity('move_in_photo_logged', null, null, $leadId, "room #$roomId, log #$id");

        return $id;
    }
}
