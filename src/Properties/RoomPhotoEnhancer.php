<?php

declare(strict_types=1);

namespace App\Properties;

use App\AI\Memory\EpisodicLogger;
use App\AI\ModelRouter;
use App\AI\Skills\SkillSupport;
use App\Core\Database;
use GdImage;
use RuntimeException;

/**
 * AI photo touch-up for owner-uploaded room photos.
 *
 * Owners photograph their rooms on a phone, at night, under a tungsten bulb,
 * holding the camera crooked. The room is fine; the photo is not — and a dark
 * yellow photo loses enquiries a good one wins. This is the admin's one-click
 * fix for that.
 *
 * It corrects the photograph, never the room. The model is shown the actual
 * image and returns a *recipe* — exposure, contrast, saturation, white balance,
 * tilt, sharpness — which GD then applies deterministically. There is no
 * generative step anywhere in this file, so it is structurally impossible for a
 * touched-up photo to show furniture, finishes or a renovation the room does
 * not have. That is the same rule the promo reels follow (see
 * App\Content\RoomVideoComposer): the advertising may only ever show the unit
 * the customer would actually move into.
 *
 * Two safety nets, because a demo cannot depend on a live API:
 *  - the histogram is measured locally first and handed to the model as
 *    numbers, so its judgement is grounded even on a photo it reads poorly;
 *  - if the call fails or comes back unusable, the measured correction is
 *    applied on its own and the row is labelled as such.
 *
 * The original file is never overwritten — enhancing writes a new file and
 * parks the untouched path in room_images.original_path, which is also what
 * makes Revert exact rather than approximate.
 */
final class RoomPhotoEnhancer
{
    private const OUTPUT_DIR = '/assets/img/uploads/rooms';

    /** Longest edge of the stored result. Bigger than any listing card needs. */
    private const MAX_EDGE = 1600;

    /** Longest edge of the copy sent to the model — vision cost is per pixel. */
    private const INSPECT_EDGE = 768;

    /** Sampling grid for the histogram; ~90k samples reads any photo fairly. */
    private const SAMPLE_TARGET = 90_000;

    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const SYSTEM = <<<'PROMPT'
    You are a property photo editor for BeLive, a Malaysian co-living operator. You are given
    one photograph of a real rental room, uploaded by the property owner, plus measurements
    taken from its histogram.

    Your job is to make the PHOTOGRAPH better. It is NOT to make the ROOM look like a
    different room. You may only correct capture faults: exposure, contrast, colour
    saturation, white balance, camera tilt and softness. You cannot add, remove, restyle or
    renovate anything in the frame, and you must not ask for a correction so heavy that the
    result misrepresents the room's real condition, lighting or colours. A tenant who views
    this room in person must recognise it immediately. When a photo is already good, say so
    and return small numbers — doing nothing is a valid answer.

    Typical faults in these uploads: shot at night under a yellow bulb (warm cast, dark),
    shot into a window (blown highlights, dark room), flat grey phone HDR, and a tilted
    horizon.

    Reply with ONLY this JSON object, no prose and no code fence:
    {
      "verdict": "one plain sentence an admin reads before approving, naming what was wrong",
      "issues": ["short fault labels, [] when the photo is already good"],
      "brightness": 0,
      "contrast": 0,
      "saturation": 0,
      "warmth": 0,
      "straighten": 0,
      "sharpen": 0
    }

    brightness/contrast/saturation: -40 to 40, 0 = leave alone.
    warmth: -40 to 40. NEGATIVE cools a yellow/orange cast, POSITIVE warms a blue one.
    straighten: -8 to 8 degrees, POSITIVE rotates the image clockwise. Only correct a tilt
    you can actually see in the image; return 0 when unsure, because the rotation crops the
    edges of the frame.
    sharpen: 0 to 80, 0 for an already-sharp photo. Never above 40 on a noisy dark photo —
    it amplifies the grain.
    PROMPT;

    /**
     * Touch up one gallery photo and repoint the row at the result.
     *
     * Re-running always re-reads the untouched original, so clicking twice can
     * never compound the correction into mush.
     *
     * @return array{id:int, image_path:string, original_path:string, verdict:string, issues:string[], recipe:array<string,float|int>, model:string, grounded:bool}
     */
    public static function enhance(int $imageId, ?string $brief = null): array
    {
        $row = Database::run('SELECT * FROM room_images WHERE id = ? LIMIT 1', [$imageId])->fetch();
        if (!$row) {
            throw new RuntimeException('That room photo no longer exists.');
        }

        // The untouched upload is the source every time — never the previous result.
        $sourcePath = (string) ($row['original_path'] ?? '') !== ''
            ? (string) $row['original_path']
            : (string) $row['image_path'];
        if (str_ends_with(strtolower($sourcePath), '.mp4')) {
            throw new RuntimeException('Video tours cannot be touched up — this only works on photos.');
        }
        // The "photo coming soon" placeholders are SVG line art, which GD cannot
        // decode — and there is no photograph in them to correct. Say so plainly
        // instead of failing later with "could not be read from disk".
        if (str_contains($sourcePath, '/rooms/placeholders/')) {
            throw new RuntimeException('That is the "photo coming soon" placeholder, not a photograph. Upload a real room photo and it will replace it.');
        }

        $absoluteSource = self::absolutePath($sourcePath);
        $bytes = is_file($absoluteSource) ? file_get_contents($absoluteSource) : false;
        $image = $bytes !== false ? @imagecreatefromstring($bytes) : false;
        if ($image === false) {
            throw new RuntimeException('The original photo file could not be read from disk.');
        }

        $fitted = self::fitWithin($image, self::MAX_EDGE);
        if ($fitted !== $image) {
            imagedestroy($image);
            $image = $fitted;
        }

        try {
            $measurement = self::measure($image);
            $judgement = self::judge($image, $measurement, $brief, (int) $row['room_id']);

            $enhanced = self::apply($image, $judgement['recipe']);
            try {
                $newPath = self::write($enhanced, (int) $row['room_id'], $sourcePath);
            } finally {
                if ($enhanced !== $image) {
                    imagedestroy($enhanced);
                }
            }
        } finally {
            imagedestroy($image);
        }

        $previousEnhanced = (string) ($row['original_path'] ?? '') !== '' ? (string) $row['image_path'] : null;

        try {
            Database::run(
                'UPDATE room_images
                    SET image_path = ?, original_path = ?, enhanced_at = NOW(),
                        enhanced_by_model = ?, enhance_note = ?, enhance_recipe = ?
                  WHERE id = ?',
                [
                    $newPath,
                    $sourcePath,
                    mb_substr($judgement['model'], 0, 80),
                    mb_substr($judgement['verdict'], 0, 500),
                    json_encode([
                        'recipe'      => $judgement['recipe'],
                        'issues'      => $judgement['issues'],
                        'measurement' => $measurement,
                        'grounded'    => $judgement['grounded'],
                    ], JSON_UNESCAPED_UNICODE),
                    $imageId,
                ]
            );
        } catch (\Throwable $e) {
            self::deleteIfUnreferenced($newPath);
            throw $e;
        }

        // Only now is the superseded result safe to drop.
        if ($previousEnhanced !== null) {
            self::deleteIfUnreferenced($previousEnhanced);
        }

        EpisodicLogger::activity(
            'room_photo_enhanced',
            'content_creation',
            $judgement['model'],
            null,
            sprintf(
                'Photo #%d on room #%d touched up (%s)%s',
                $imageId,
                (int) $row['room_id'],
                $judgement['issues'] === [] ? 'polish only' : implode(', ', $judgement['issues']),
                $judgement['grounded'] ? '' : ' — measured fallback, model unavailable'
            )
        );

        return [
            'id'            => $imageId,
            'image_path'    => $newPath,
            'original_path' => $sourcePath,
            'verdict'       => $judgement['verdict'],
            'issues'        => $judgement['issues'],
            'recipe'        => $judgement['recipe'],
            'model'         => $judgement['model'],
            'grounded'      => $judgement['grounded'],
        ];
    }

    /** Put the owner's untouched upload back on the listing. */
    public static function revert(int $imageId): array
    {
        $row = Database::run('SELECT * FROM room_images WHERE id = ? LIMIT 1', [$imageId])->fetch();
        if (!$row) {
            throw new RuntimeException('That room photo no longer exists.');
        }
        $original = (string) ($row['original_path'] ?? '');
        if ($original === '') {
            throw new RuntimeException('That photo is the owner\'s original already — there is nothing to revert.');
        }
        if (!is_file(self::absolutePath($original))) {
            throw new RuntimeException('The original photo file is missing from disk, so the touch-up cannot be undone.');
        }

        $enhanced = (string) $row['image_path'];
        Database::run(
            'UPDATE room_images
                SET image_path = ?, original_path = NULL, enhanced_at = NULL,
                    enhanced_by_model = NULL, enhance_note = NULL, enhance_recipe = NULL
              WHERE id = ?',
            [$original, $imageId]
        );
        self::deleteIfUnreferenced($enhanced);

        EpisodicLogger::activity(
            'room_photo_reverted',
            'content_creation',
            null,
            null,
            sprintf('Photo #%d on room #%d restored to the owner\'s original.', $imageId, (int) $row['room_id'])
        );

        return ['id' => $imageId, 'image_path' => $original];
    }

    // ---------------------------------------------------------------- judging

    /**
     * Ask the routed model what this photo needs, grounded in the measurements.
     * Any failure — no credential, API down, unparseable reply — lands on the
     * measured correction rather than an error, because an admin clicking
     * "Improve" during a demo must get a better photo either way.
     *
     * @return array{recipe:array<string,float|int>, verdict:string, issues:string[], model:string, grounded:bool}
     */
    private static function judge(GdImage $image, array $measurement, ?string $brief, int $roomId): array
    {
        $fallback = self::measuredRecipe($measurement);

        try {
            $client = ModelRouter::clientForPhase('content_creation');
            $brief = $brief !== null ? trim($brief) : '';

            $prompt = implode("\n\n", array_filter([
                'HISTOGRAM MEASUREMENTS (taken from this exact file): '
                    . json_encode($measurement, JSON_UNESCAPED_UNICODE),
                'PURELY MEASURED CORRECTION (what the numbers alone suggest — you can see the photo, so overrule it where your eyes disagree): '
                    . json_encode($fallback, JSON_UNESCAPED_UNICODE),
                $brief !== '' ? "ADMIN NOTE ABOUT THIS PHOTO:\n" . mb_substr($brief, 0, 500) : '',
            ]));

            [$result, $ms] = SkillSupport::timed(fn () => $client->generate(
                self::SYSTEM,
                [['role' => 'user', 'content' => $prompt]],
                [
                    'max_tokens'  => 500,
                    'temperature' => 0.2,
                    'mock_hint'   => 'photo',
                    'image'       => self::inspectionCopy($image),
                ]
            ));

            $parsed = SkillSupport::extractJson($result['text']);
            if ($parsed === null) {
                throw new RuntimeException('The model did not return a usable touch-up recipe.');
            }

            $recipe = self::clampRecipe($parsed);
            $verdict = trim((string) ($parsed['verdict'] ?? '')) ?: 'Touched up from the model\'s read of the photo.';
            $issues = array_values(array_filter(array_map(
                static fn ($issue): string => mb_substr(trim((string) $issue), 0, 60),
                is_array($parsed['issues'] ?? null) ? $parsed['issues'] : []
            )));

            EpisodicLogger::log([
                'phase'        => 'content_creation',
                'skill'        => 'create',
                'model_used'   => $result['model'],
                'direction'    => 'internal',
                'message_out'  => $verdict,
                'message_kind' => 'photo_touchup',
                'entities'     => ['room_id' => $roomId, 'recipe' => $recipe, 'issues' => $issues],
                'reasoning'    => sprintf(
                    'Read room #%d photo against its histogram (%s) and returned a correction recipe. Capture faults only — no generated imagery.',
                    $roomId,
                    $issues === [] ? 'no faults called' : implode(', ', $issues)
                ),
                'response_ms'  => $ms,
            ]);

            return [
                'recipe'   => $recipe,
                'verdict'  => $verdict,
                'issues'   => $issues,
                'model'    => $result['model'],
                'grounded' => true,
            ];
        } catch (\Throwable $e) {
            error_log('[room photo enhance] model unavailable, using measured correction: ' . $e->getMessage());

            return [
                'recipe'   => $fallback,
                'verdict'  => self::measuredVerdict($measurement),
                'issues'   => self::measuredIssues($measurement),
                'model'    => 'histogram (no model)',
                'grounded' => false,
            ];
        }
    }

    /**
     * The correction the numbers alone justify. Deliberately gentle: without
     * eyes on the frame it must not guess at tilt, and it under-corrects rather
     * than risk making a truthful photo look staged.
     *
     * @return array<string, float|int>
     */
    private static function measuredRecipe(array $m): array
    {
        $warmth = 0.0;
        if ($m['cast_strength_pct'] > 6.0) {
            $warmth = match ($m['colour_cast']) {
                'warm'  => -$m['cast_strength_pct'] * 0.8,
                'cool'  => $m['cast_strength_pct'] * 0.8,
                default => 0.0,
            };
        }

        return self::clampRecipe([
            'brightness' => (50.0 - $m['mean_luminance_pct']) * 0.85,
            'contrast'   => (62.0 - $m['contrast_spread_pct']) * 0.45,
            'saturation' => (30.0 - $m['saturation_pct']) * 0.5,
            'warmth'     => $warmth,
            'straighten' => 0.0,
            'sharpen'    => $m['mean_luminance_pct'] < 30.0 ? 15 : 25,
        ]);
    }

    /** @return string[] */
    private static function measuredIssues(array $m): array
    {
        $issues = [];
        if ($m['mean_luminance_pct'] < 42.0) {
            $issues[] = 'underexposed';
        } elseif ($m['mean_luminance_pct'] > 63.0) {
            $issues[] = 'overexposed';
        }
        if ($m['contrast_spread_pct'] < 50.0) {
            $issues[] = 'flat contrast';
        }
        if ($m['highlight_clip_pct'] > 4.0) {
            $issues[] = 'blown highlights';
        }
        if ($m['cast_strength_pct'] > 6.0 && $m['colour_cast'] !== 'neutral') {
            $issues[] = $m['colour_cast'] . ' colour cast';
        }

        return $issues;
    }

    private static function measuredVerdict(array $m): string
    {
        $issues = self::measuredIssues($m);

        return $issues === []
            ? 'No AI model was reachable; the histogram found nothing badly wrong, so only a light polish was applied.'
            : 'No AI model was reachable, so the histogram correction was applied on its own — ' . implode(', ', $issues) . '.';
    }

    /**
     * Every number the pipeline will act on, forced into its documented range.
     * A model that returns "brightness": 400 gets a sane photo back regardless.
     *
     * @return array<string, float|int>
     */
    private static function clampRecipe(array $raw): array
    {
        $clamp = static fn ($value, float $min, float $max): float => max($min, min($max, (float) ($value ?? 0)));

        return [
            'brightness' => round($clamp($raw['brightness'] ?? 0, -40, 40), 1),
            'contrast'   => round($clamp($raw['contrast'] ?? 0, -25, 25), 1),
            'saturation' => round($clamp($raw['saturation'] ?? 0, -30, 30), 1),
            'warmth'     => round($clamp($raw['warmth'] ?? 0, -40, 40), 1),
            'straighten' => round($clamp($raw['straighten'] ?? 0, -8, 8), 2),
            'sharpen'    => (int) round($clamp($raw['sharpen'] ?? 0, 0, 80)),
        ];
    }

    // ------------------------------------------------------------- inspection

    /**
     * What the histogram says about this photo, in units a language model can
     * reason about: percentages, not raw 0-255 byte values.
     *
     * @return array<string, float|int|string>
     */
    public static function measure(GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        // Sample on a grid rather than every pixel: a 1600px photo is 2M pixels
        // and the statistics are identical from 90k of them.
        $step = max(1, (int) sqrt(($width * $height) / self::SAMPLE_TARGET));

        $histogram = array_fill(0, 256, 0);
        $samples = 0;
        $sumR = $sumG = $sumB = 0;
        $sumSaturation = 0.0;

        for ($y = 0; $y < $height; $y += $step) {
            for ($x = 0; $x < $width; $x += $step) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                $luminance = (int) round(0.299 * $r + 0.587 * $g + 0.114 * $b);
                $histogram[$luminance]++;
                $sumR += $r;
                $sumG += $g;
                $sumB += $b;

                $max = max($r, $g, $b);
                $sumSaturation += $max === 0 ? 0.0 : ($max - min($r, $g, $b)) / $max;
                $samples++;
            }
        }

        if ($samples === 0) {
            throw new RuntimeException('The photo could not be measured.');
        }

        $percentile = static function (int $target) use ($histogram, $samples): int {
            $cutoff = $samples * $target / 100;
            $running = 0;
            foreach ($histogram as $value => $count) {
                $running += $count;
                if ($running >= $cutoff) {
                    return $value;
                }
            }

            return 255;
        };

        $meanR = $sumR / $samples;
        $meanG = $sumG / $samples;
        $meanB = $sumB / $samples;
        $meanAll = max(1.0, ($meanR + $meanG + $meanB) / 3);

        // Grey-world: a room is close enough to neutral on average that a
        // standing red-over-blue gap is the bulb, not the furniture.
        $warmCool = ($meanR - $meanB) / $meanAll * 100;
        $greenMagenta = ($meanG - ($meanR + $meanB) / 2) / $meanAll * 100;

        [$cast, $strength] = abs($warmCool) >= abs($greenMagenta)
            ? [$warmCool > 0 ? 'warm' : 'cool', abs($warmCool)]
            : [$greenMagenta > 0 ? 'green' : 'magenta', abs($greenMagenta)];

        $shadowClipped = array_sum(array_slice($histogram, 0, 8));
        $highlightClipped = array_sum(array_slice($histogram, 248));

        return [
            'width'                => $width,
            'height'               => $height,
            'mean_luminance_pct'   => round((0.299 * $meanR + 0.587 * $meanG + 0.114 * $meanB) / 255 * 100, 1),
            'contrast_spread_pct'  => round(($percentile(95) - $percentile(5)) / 255 * 100, 1),
            'shadow_clip_pct'      => round($shadowClipped / $samples * 100, 1),
            'highlight_clip_pct'   => round($highlightClipped / $samples * 100, 1),
            'saturation_pct'       => round($sumSaturation / $samples * 100, 1),
            'colour_cast'          => $strength < 3.0 ? 'neutral' : $cast,
            'cast_strength_pct'    => round($strength, 1),
        ];
    }

    /**
     * A small JPEG of the photo for the model to look at.
     *
     * @return array{mime:string, data:string}
     */
    private static function inspectionCopy(GdImage $image): array
    {
        $small = self::fitWithin($image, self::INSPECT_EDGE);
        ob_start();
        imagejpeg($small, null, 82);
        $bytes = (string) ob_get_clean();
        if ($small !== $image) {
            imagedestroy($small);
        }

        return ['mime' => 'image/jpeg', 'data' => base64_encode($bytes)];
    }

    // -------------------------------------------------------------- rendering

    /**
     * Apply the recipe. Geometry first, then tone, then colour, then detail —
     * sharpening before a rotation would sharpen the interpolation artefacts
     * the rotation is about to introduce.
     *
     * @param array<string, float|int> $recipe
     */
    public static function apply(GdImage $image, array $recipe): GdImage
    {
        $working = abs((float) $recipe['straighten']) >= 0.2
            ? self::straighten($image, (float) $recipe['straighten'])
            : $image;

        if ((float) $recipe['brightness'] !== 0.0) {
            imagefilter($working, IMG_FILTER_BRIGHTNESS, (int) round((float) $recipe['brightness'] * 2.55));
        }
        if ((float) $recipe['contrast'] !== 0.0) {
            // GD reads this backwards: a negative level is *more* contrast.
            imagefilter($working, IMG_FILTER_CONTRAST, (int) round(-(float) $recipe['contrast']));
        }
        if ((float) $recipe['saturation'] !== 0.0 || (float) $recipe['warmth'] !== 0.0) {
            self::colourPass($working, (float) $recipe['saturation'], (float) $recipe['warmth']);
        }
        if ((int) $recipe['sharpen'] > 0) {
            $amount = (int) $recipe['sharpen'] / 100;
            @imageconvolution($working, [
                [0.0, -$amount, 0.0],
                [-$amount, 1 + 4 * $amount, -$amount],
                [0.0, -$amount, 0.0],
            ], 1.0, 0.0);
        }

        return $working;
    }

    /**
     * Saturation and white balance in one pass over the pixels — GD has a
     * native filter for neither, and two passes would double the cost of the
     * slowest step in the whole touch-up.
     */
    private static function colourPass(GdImage $image, float $saturation, float $warmth): void
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = 1 + $saturation / 100;
        $shift = $warmth / 100 * 28; // red up / blue down, in 0-255 units

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                if ($shift !== 0.0) {
                    $r += $shift;
                    $b -= $shift;
                }
                if ($scale !== 1.0) {
                    $grey = 0.299 * $r + 0.587 * $g + 0.114 * $b;
                    $r = $grey + ($r - $grey) * $scale;
                    $g = $grey + ($g - $grey) * $scale;
                    $b = $grey + ($b - $grey) * $scale;
                }

                imagesetpixel($image, $x, $y, (self::byte($r) << 16) | (self::byte($g) << 8) | self::byte($b));
            }
        }
    }

    private static function byte(float $value): int
    {
        return (int) max(0, min(255, round($value)));
    }

    /**
     * Rotate by $degrees clockwise and crop back to the largest rectangle of
     * the original shape that still contains only real pixels — otherwise a
     * straightened photo gains black triangles in its corners.
     */
    private static function straighten(GdImage $image, float $degrees): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        // imagerotate turns counter-clockwise, the recipe is stated clockwise.
        $rotated = imagerotate($image, -$degrees, 0);
        if ($rotated === false) {
            return $image;
        }

        [$cropWidth, $cropHeight] = self::largestInnerRect($width, $height, deg2rad(abs($degrees)));
        $cropWidth = (int) floor($cropWidth);
        $cropHeight = (int) floor($cropHeight);
        if ($cropWidth < 16 || $cropHeight < 16) {
            imagedestroy($rotated);

            return $image;
        }

        $cropped = imagecrop($rotated, [
            'x'      => (int) round((imagesx($rotated) - $cropWidth) / 2),
            'y'      => (int) round((imagesy($rotated) - $cropHeight) / 2),
            'width'  => $cropWidth,
            'height' => $cropHeight,
        ]);
        imagedestroy($rotated);

        return $cropped === false ? $image : $cropped;
    }

    /**
     * Largest axis-aligned rectangle of the original aspect ratio that fits
     * inside a w x h rectangle rotated by $angle radians.
     *
     * @return array{0: float, 1: float}
     */
    private static function largestInnerRect(float $width, float $height, float $angle): array
    {
        $sin = abs(sin($angle));
        $cos = abs(cos($angle));
        $widthIsLonger = $width >= $height;
        $long = $widthIsLonger ? $width : $height;
        $short = $widthIsLonger ? $height : $width;

        if ($short <= 2 * $sin * $cos * $long || abs($sin - $cos) < 1e-10) {
            $half = 0.5 * $short;

            return $widthIsLonger ? [$half / $sin, $half / $cos] : [$half / $cos, $half / $sin];
        }

        $denominator = $cos * $cos - $sin * $sin;

        return [
            ($width * $cos - $height * $sin) / $denominator,
            ($height * $cos - $width * $sin) / $denominator,
        ];
    }

    /** Downscale so the longest edge is at most $edge. Returns $image untouched when it already fits. */
    private static function fitWithin(GdImage $image, int $edge): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);
        if ($longest <= $edge) {
            return $image;
        }

        $scaled = imagescale($image, (int) round($width * $edge / $longest), (int) round($height * $edge / $longest));

        return $scaled === false ? $image : $scaled;
    }

    // ------------------------------------------------------------------ files

    /** Write the result beside the uploads, in the source's own format. */
    private static function write(GdImage $image, int $roomId, string $sourcePath): string
    {
        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONS, true)) {
            $extension = 'jpg';
        }

        $directory = APP_ROOT . '/public' . self::OUTPUT_DIR;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('The room photo folder could not be created.');
        }

        $filename = sprintf('room_%d_ai_%s_%s.%s', $roomId, date('Ymd_His'), bin2hex(random_bytes(5)), $extension);
        $absolute = $directory . '/' . $filename;

        $written = match ($extension) {
            'png'  => imagepng($image, $absolute, 6),
            'webp' => imagewebp($image, $absolute, 88),
            default => imagejpeg($image, $absolute, 90),
        };
        if (!$written) {
            @unlink($absolute);
            throw new RuntimeException('The touched-up photo could not be saved.');
        }

        return self::OUTPUT_DIR . '/' . $filename;
    }

    /**
     * Delete a generated file only once nothing points at it. Seeded gallery
     * art is shared between demo rooms, so "no row references this" is the only
     * safe test — and files outside the uploads folder are never ours to delete.
     */
    private static function deleteIfUnreferenced(string $path): void
    {
        if (!str_starts_with($path, self::OUTPUT_DIR . '/')) {
            return;
        }
        $stillUsed = (int) Database::run(
            'SELECT COUNT(*) FROM room_images WHERE image_path = ? OR original_path = ?',
            [$path, $path]
        )->fetchColumn();
        if ($stillUsed > 0) {
            return;
        }

        $absolute = self::absolutePath($path);
        if (is_file($absolute) && !unlink($absolute)) {
            error_log('[room photo enhance] Could not remove superseded file ' . basename($absolute));
        }
    }

    /** Site-local path to a real one, refusing anything that climbs out of public/. */
    private static function absolutePath(string $path): string
    {
        if (!str_starts_with($path, '/assets/') || str_contains($path, '..')) {
            throw new RuntimeException('That photo path is not inside the site\'s media folder.');
        }

        return APP_ROOT . '/public' . $path;
    }
}
