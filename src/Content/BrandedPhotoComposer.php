<?php

declare(strict_types=1);

namespace App\Content;

use RuntimeException;

/**
 * Puts BeLive's mascot on the photo a post publishes, so a photo post carries
 * the same character the promo videos do.
 *
 * The room photography itself is never touched: the source file stays exactly
 * as the owner uploaded it (the gallery, the WhatsApp sends and the portals all
 * keep showing the clean original) and the branded copy is written alongside it
 * under uploads/, used only as the post's image.
 *
 * Everything here is optional by design — a room photo that cannot be decoded,
 * a missing mascot, a GD failure — because a caption post that reaches the
 * platform unbranded still sells a room, and one that never posts sells nothing.
 * Callers get null and fall back to the plain photo.
 */
final class BrandedPhotoComposer
{
    private const OUTPUT_DIR = '/assets/img/uploads/branded';

    /** The mascot stands this proportion of the photo's height. */
    private const MASCOT_SCALE = 0.42;

    /**
     * Brand a room photo for one post.
     *
     * @param string $photoPath site-local path (/assets/img/rooms/...)
     * @param string $caption   used only to choose the pose that fits the words
     * @return string|null site-local path to the branded copy, or null when the
     *                     photo could not be branded and the original should be used
     */
    public static function brand(string $photoPath, string $caption, int $roomId): ?string
    {
        if (!MascotLibrary::isAvailable()) {
            return null;
        }

        $source = APP_ROOT . '/public' . $photoPath;
        // SVG placeholders are line art with nothing to brand, and GD cannot
        // read them anyway.
        if (!is_file($source) || str_ends_with(strtolower($photoPath), '.svg')) {
            return null;
        }

        try {
            return self::compose($source, $caption, $roomId);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function compose(string $source, string $caption, int $roomId): ?string
    {
        $bytes = file_get_contents($source);
        $photo = $bytes !== false ? @imagecreatefromstring($bytes) : false;
        if ($photo === false) {
            return null;
        }

        try {
            $width = imagesx($photo);
            $height = imagesy($photo);
            imagealphablending($photo, true);

            MascotLibrary::stamp(
                $photo,
                MascotLibrary::forPhoto($caption),
                (int) round($height * self::MASCOT_SCALE),
                $width - (int) round($width * 0.04),
                $height - (int) round($height * 0.04),
                'right'
            );

            $directory = APP_ROOT . '/public' . self::OUTPUT_DIR;
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('The branded photo folder could not be created.');
            }

            $filename = sprintf('room_%d_%s_%s.jpg', $roomId, date('Ymd_His'), bin2hex(random_bytes(4)));
            if (!imagejpeg($photo, $directory . '/' . $filename, 88)) {
                throw new RuntimeException('The branded photo could not be written.');
            }

            return self::OUTPUT_DIR . '/' . $filename;
        } finally {
            imagedestroy($photo);
        }
    }
}
