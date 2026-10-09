<?php

declare(strict_types=1);

namespace App\Content;

use App\Models\Room;
use App\Properties\RoomPhotoEnhancer;
use RuntimeException;

/** Campaign photography: measured touch-up, an in-scene host, and verified copy. */
final class BrandedPhotoComposer
{
    private const OUTPUT_DIR = '/assets/img/uploads/branded';

    public static function brand(string $photoPath, string $caption, int $roomId, ?string $brief = null, ?array &$details = null): ?string
    {
        $details = null;
        $root = realpath(APP_ROOT . '/public');
        $source = realpath(APP_ROOT . '/public' . $photoPath);
        if ($root === false || $source === false || !str_starts_with($source, $root . DIRECTORY_SEPARATOR)
            || str_ends_with(strtolower($source), '.svg')) {
            return null;
        }
        try {
            return self::compose($source, $caption, $roomId, $brief, $details);
        } catch (\Throwable $e) {
            error_log('[campaign photo] ' . $e->getMessage());
            return null;
        }
    }

    private static function compose(string $source, string $caption, int $roomId, ?string $brief, ?array &$details): ?string
    {
        $bytes = file_get_contents($source);
        $original = $bytes !== false ? @imagecreatefromstring($bytes) : false;
        if ($original === false) {
            return null;
        }
        $photo = $original;
        try {
            $polish = RoomPhotoEnhancer::polish($photo, $brief, $roomId);
            $photo = $polish['image'];
            $width = imagesx($photo);
            $height = imagesy($photo);
            imagealphablending($photo, true);
            // Put the presenter on the quieter side of the foreground.
            $side = self::quietSide($photo);
            // Use an inward-facing gesture from the original artwork; flipping
            // the character would also reverse the beLive wordmark on its cape.
            MascotLibrary::place($photo, $side === 'right' ? 'waving' : 'wink-point', (int) round($height * 0.29),
                (int) round($width * ($side === 'right' ? 0.84 : 0.16)), (int) ($height * 0.87));

            $fonts = RoomVideoComposer::fonts();
            $room = Room::find($roomId);
            if ($fonts !== null && $room !== null && $width >= 480 && $height >= 360) {
                $margin = max(20, (int) ($width * 0.035));
                $size = max(16, (int) ($width * 0.026));
                $white = imagecolorallocate($photo, 255, 255, 255);
                $teal = imagecolorallocate($photo, 64, 192, 191);
                // Soft falloff leaves the furniture and layout visible.
                for ($y = 0; $y < (int) ($height * 0.19); $y++) {
                    $alpha = (int) (65 + 62 * $y / ($height * 0.19));
                    imageline($photo, 0, $y, $width, $y, imagecolorallocatealpha($photo, 15, 28, 36, $alpha));
                }
                for ($y = (int) ($height * 0.89); $y < $height; $y++) {
                    $alpha = (int) (127 - 80 * ($y - $height * 0.89) / ($height * 0.11));
                    imageline($photo, 0, $y, $width, $y, imagecolorallocatealpha($photo, 15, 28, 36, $alpha));
                }
                imagettftext($photo, $size, 0, $margin, $margin + $size, $teal, $fonts['bold'], 'beLive / ROOM STORIES');
                $title = self::fitText((string) $room['name'], $fonts['bold'], $size * 1.15, $width - 2 * $margin);
                imagettftext($photo, $size * 1.15, 0, $margin, $margin + (int) ($size * 2.7), $white, $fonts['bold'], $title);
                $area = self::fitText((string) ($room['location'] ?? ''), $fonts['regular'], $size * 0.7, $width - 2 * $margin);
                imagettftext($photo, $size * 0.7, 0, $margin, $margin + (int) ($size * 3.9), $white, $fonts['regular'], $area);
                $prices = Room::prices($roomId);
                $tenure = isset($prices['12_month']) ? '12_month' : 'monthly';
                if (isset($prices[$tenure])) {
                    $price = 'RM ' . number_format((float) $prices[$tenure]['price']) . '/mo · '
                        . ($tenure === '12_month' ? '12 months+' : 'monthly stay');
                    imagettftext($photo, $size * 0.85, 0, $margin, $height - $margin, $white, $fonts['bold'], $price);
                }
            }
            $directory = APP_ROOT . '/public' . self::OUTPUT_DIR;
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('The campaign photo folder could not be created.');
            }
            $filename = sprintf('room_%d_%s_%s.jpg', $roomId, date('Ymd_His'), bin2hex(random_bytes(4)));
            if (!imagejpeg($photo, $directory . '/' . $filename, 92)) {
                throw new RuntimeException('The campaign photo could not be written.');
            }
            $details = [
                'style' => 'room_story', 'enhancement' => $polish['recipe'],
                'enhancement_model' => $polish['model'], 'enhancement_note' => $polish['verdict'],
                'mascot' => MascotLibrary::isAvailable(), 'placement' => $side,
            ];
            return self::OUTPUT_DIR . '/' . $filename;
        } finally {
            if ($photo !== $original) {
                imagedestroy($photo);
            }
            imagedestroy($original);
        }
    }

    private static function quietSide(\GdImage $image): string
    {
        $scores = [];
        foreach (['left' => 0.16, 'right' => 0.84] as $side => $centre) {
            $values = [];
            for ($y = 0.6; $y <= 0.85; $y += 0.025) {
                for ($x = $centre - 0.10; $x <= $centre + 0.10; $x += 0.02) {
                    $c = imagecolorat($image, (int) (imagesx($image) * $x), (int) (imagesy($image) * $y));
                    $values[] = (($c >> 16) & 255) * 0.21 + (($c >> 8) & 255) * 0.72 + ($c & 255) * 0.07;
                }
            }
            $mean = array_sum($values) / count($values);
            $scores[$side] = array_sum(array_map(static fn ($v) => ($v - $mean) ** 2, $values)) / count($values);
        }
        return $scores['left'] < $scores['right'] ? 'left' : 'right';
    }

    private static function fitText(string $text, string $font, float $size, int $width): string
    {
        while (mb_strlen($text) > 1) {
            $box = imagettfbbox($size, 0, $font, $text);
            if ($box !== false && $box[2] - $box[0] <= $width) {
                break;
            }
            $text = rtrim(mb_substr($text, 0, -2)) . '…';
        }
        return $text;
    }
}
