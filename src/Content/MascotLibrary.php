<?php

declare(strict_types=1);

namespace App\Content;

/**
 * BeLive's mascot — the caped smart lock — and the rule for which of its poses
 * belongs on a given piece of content.
 *
 * The artwork is BeLive's own (the AI SOLOPRENEUR CHALLENGE 2026 set), copied
 * into public/assets/img/mascot/ under role names rather than the delivery
 * numbering, so a caller asks for the pose it means instead of remembering that
 * "Masscot-14-19" is the one holding a megaphone.
 *
 * A pose is chosen from what a scene actually says: a price line gets the
 * trophy, a zero-deposit line gets the fist pump, the closing card gets the
 * megaphone. The choice is deterministic — re-rendering the same script puts
 * the same character in the same place.
 */
final class MascotLibrary
{
    public const DIR = '/assets/img/mascot';

    /** Every pose in the set, by the role it reads as. */
    public const POSES = [
        'hero', 'flying', 'popcorn', 'zen', 'trophy', 'dancing', 'cool', 'sweat',
        'determined', 'heart', 'running', 'megaphone-small', 'thinking', 'soaring',
        'cheering', 'laughing', 'shrug', 'wink-point', 'megaphone', 'waving',
        'laptop', 'dashing', 'pondering',
    ];

    /**
     * Poses that read as an invitation rather than a reaction — the rotation a
     * scene falls back to when nothing in its words suggests a pose.
     */
    private const ROTATION = ['wink-point', 'cool', 'laughing', 'dancing', 'hero', 'cheering'];

    /** Words that clearly call for one specific pose. Checked in order. */
    private const CUES = [
        'trophy'     => ['/\brm\s?[\d,]/i', '/\bprice/i', '/\bbest value/i', '/\/mo\b/i'],
        'cheering'   => ['/zero deposit/i', '/\bno deposit/i', '/\bfree\b/i', '/\bsav(e|ing)/i'],
        'waving'     => ['/\bhello\b/i', '/\bwelcome/i', '/\bmeet\b/i', '/\bnew\b/i'],
        'heart'      => ['/\blove/i', '/\bhome\b/i', '/\bcomfort/i', '/\bcosy|cozy\b/i'],
        'zen'        => ['/\bclean/i', '/\bquiet/i', '/\bcalm/i', '/\brelax/i'],
        'laptop'     => ['/\bwifi\b/i', '/\bwork/i', '/\bstudy/i', '/\bstudent/i', '/\binternet/i'],
        'dashing'    => ['/\bmove in\b/i', '/\bfast\b/i', '/\btoday\b/i', '/\bnow\b/i', '/\bready\b/i'],
        'cool'       => ['/\bfurnish/i', '/\bstyle/i', '/\bmodern/i', '/\bfacilit/i', '/\bgym\b/i', '/\bpool\b/i'],
    ];

    /** Absolute path to a pose's file, or null when it is not on disk. */
    public static function file(string $pose): ?string
    {
        if (!in_array($pose, self::POSES, true)) {
            return null;
        }
        $file = APP_ROOT . '/public' . self::DIR . '/' . $pose . '.png';

        return is_file($file) ? $file : null;
    }

    public static function isAvailable(): bool
    {
        return self::file('hero') !== null;
    }

    /**
     * The pose for one video scene. The closing card always gets the megaphone —
     * it is the frame that asks for the message — and the opening scene always
     * waves, so the mascot greets before it sells.
     */
    public static function forScene(int $index, string $text, bool $isCta = false): string
    {
        if ($isCta) {
            return 'megaphone';
        }
        if ($index === 0) {
            return 'waving';
        }

        return self::fromCues($text) ?? self::ROTATION[$index % count(self::ROTATION)];
    }

    /**
     * The pose for a photo post, read off the caption. A still has one chance to
     * carry the character, so the cue matters more here than the rotation does.
     */
    public static function forPhoto(string $caption): string
    {
        return self::fromCues($caption) ?? 'hero';
    }

    /**
     * Stamp a pose onto a GD canvas, anchored by its feet so it always stands
     * on the line it was given rather than floating off it, and ringed in a
     * white sticker edge so it never disappears into a pale wardrobe or a
     * bright window. A missing pose file is skipped: the post is still
     * publishable without the character, and half a mascot is worse than none.
     *
     * @param \GdImage $canvas
     * @param 'right'|'centre' $anchor which edge of the artwork $x refers to
     */
    public static function stamp($canvas, ?string $pose, int $targetHeight, int $x, int $bottomY, string $anchor = 'right'): void
    {
        $file = $pose !== null ? self::file($pose) : null;
        if ($file === null) {
            return;
        }

        $mascot = @imagecreatefrompng($file);
        if ($mascot === false) {
            return;
        }

        $drawWidth = (int) round(imagesx($mascot) * ($targetHeight / imagesy($mascot)));
        $left = $anchor === 'centre' ? $x - (int) ($drawWidth / 2) : $x - $drawWidth;
        $top = $bottomY - $targetHeight;

        // Scale once; the outline is stamped from the same bitmap.
        $scaled = self::transparentCanvas($drawWidth, $targetHeight);
        imagecopyresampled($scaled, $mascot, 0, 0, 0, 0, $drawWidth, $targetHeight, imagesx($mascot), imagesy($mascot));
        imagedestroy($mascot);

        try {
            $outline = self::transparentCanvas($drawWidth, $targetHeight);
            imagecopy($outline, $scaled, 0, 0, 0, 0, $drawWidth, $targetHeight);
            // Grayscale then full brightness = a white silhouette; alpha is
            // untouched by both filters, so the shape stays exact.
            imagefilter($outline, IMG_FILTER_GRAYSCALE);
            imagefilter($outline, IMG_FILTER_BRIGHTNESS, 255);

            $spread = max(3, (int) round($targetHeight / 90));
            foreach ([[-1, -1], [0, -1], [1, -1], [-1, 0], [1, 0], [-1, 1], [0, 1], [1, 1]] as [$dx, $dy]) {
                imagecopy($canvas, $outline, $left + $dx * $spread, $top + $dy * $spread, 0, 0, $drawWidth, $targetHeight);
            }
            imagedestroy($outline);

            imagecopy($canvas, $scaled, $left, $top, 0, 0, $drawWidth, $targetHeight);
        } finally {
            imagedestroy($scaled);
        }
    }

    /** @return \GdImage a fully transparent truecolor canvas */
    private static function transparentCanvas(int $width, int $height)
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagesavealpha($canvas, true);
        imagealphablending($canvas, false);
        imagefilledrectangle($canvas, 0, 0, $width, $height, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        return $canvas;
    }

    private static function fromCues(string $text): ?string
    {
        foreach (self::CUES as $pose => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    return $pose;
                }
            }
        }

        return null;
    }
}
