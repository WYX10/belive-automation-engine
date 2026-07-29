<?php

declare(strict_types=1);

namespace App\Content;

use App\Models\Room;
use RuntimeException;

/**
 * Renders a room's own media into a 9:16 promo reel — the video half of the
 * content studio.
 *
 * Nothing here is generated imagery: every frame is a photo or a tour clip
 * already attached to the room, so the reel can only ever show the unit that
 * is actually being advertised (the same rule the placeholder art follows —
 * see database/generate_room_placeholders.php). The AI's contribution is the
 * words burned onto those shots, not the shots.
 *
 * Pipeline: GD draws one transparent 1080x1920 text layer per scene (scrim,
 * brand chip, headline, progress bar); ffmpeg pans/zooms each shot under its
 * layer and cross-fades the scenes into a single H.264 file with a silent AAC
 * track — platforms reject a video with no audio stream far more often than
 * one that is quiet.
 *
 * ffmpeg is the one external dependency. It is probed, never assumed:
 * isAvailable() is what the studio asks before offering a video draft.
 */
final class RoomVideoComposer
{
    private const TRANSITION = 0.5;
    private const OUTPUT_DIR = '/assets/img/uploads/videos';

    /** Bold + regular pairs, first readable pair wins. Overridable via env. */
    private const FONT_CANDIDATES = [
        ['C:/Windows/Fonts/arialbd.ttf', 'C:/Windows/Fonts/arial.ttf'],
        ['/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'],
        ['/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf', '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf'],
        ['/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/dejavu/DejaVuSans.ttf'],
        ['/Library/Fonts/Arial Bold.ttf', '/Library/Fonts/Arial.ttf'],
    ];

    // Brand palette, straight from public/assets/css/belive-theme.css.
    private const TEAL = [64, 192, 191];
    private const INK = [31, 41, 55];

    /** @var string|null|false null = not probed yet, false = not installed */
    private static string|null|false $binary = null;

    public static function isAvailable(): bool
    {
        return self::binary() !== null;
    }

    /**
     * The ffmpeg executable, or null when there is none. FFMPEG_BIN in .env
     * wins — a Windows install off PATH is the normal case in this project.
     */
    public static function binary(): ?string
    {
        if (self::$binary === null) {
            $configured = trim((string) ($_ENV['FFMPEG_BIN'] ?? ''));
            $candidate = $configured !== '' ? $configured : 'ffmpeg';
            exec(escapeshellarg($candidate) . ' -version 2>&1', $output, $code);
            self::$binary = $code === 0 ? $candidate : false;
        }

        return self::$binary === false ? null : self::$binary;
    }

    /** Forget the probe result — for tests that move FFMPEG_BIN around. */
    public static function resetBinaryProbe(): void
    {
        self::$binary = null;
    }

    /**
     * Render the reel and return its site-local path (/assets/img/uploads/...),
     * the same shape as a room photo path so every existing consumer of a media
     * URL keeps working.
     *
     * @param array<string, mixed> $room
     * @param array<int, array{headline:string, sub:string, seconds:float, cta?:bool}> $scenes
     */
    public static function render(array $room, array $scenes): string
    {
        $ffmpeg = self::binary()
            ?? throw new RuntimeException('ffmpeg is not installed (or FFMPEG_BIN in .env points nowhere), so a promo video cannot be rendered on this machine.');
        if ($scenes === []) {
            throw new RuntimeException('A promo video needs at least one scene.');
        }

        $roomId = (int) $room['id'];
        $shots = self::shotsFor($roomId);
        if ($shots === []) {
            throw new RuntimeException('This room has no photos or tour clips yet — add at least one before rendering a promo video.');
        }

        $fonts = self::fonts()
            ?? throw new RuntimeException('No TrueType font was found for the on-screen text. Point PROMO_FONT_BOLD and PROMO_FONT_REGULAR in .env at a .ttf pair.');

        $workDir = self::workDir();
        $overlays = [];
        try {
            // Pair each scene with a shot, reusing shots when the script is
            // longer than the gallery, and clamp a clip scene to the clip.
            $plan = [];
            foreach (array_values($scenes) as $i => $scene) {
                $shot = $shots[$i % count($shots)];
                $seconds = (float) $scene['seconds'];
                if ($shot['kind'] === 'clip') {
                    $seconds = min($seconds, max(1.5, $shot['duration'] - 0.2));
                }

                $isCta = (bool) ($scene['cta'] ?? false);
                $overlay = $workDir . '/overlay_' . $i . '.png';
                self::drawOverlay(
                    $overlay,
                    $fonts,
                    (string) $scene['headline'],
                    (string) ($scene['sub'] ?? ''),
                    $isCta,
                    $i + 1,
                    count($scenes),
                    MascotLibrary::forScene($i, trim($scene['headline'] . ' ' . ($scene['sub'] ?? '')), $isCta)
                );
                $overlays[] = $overlay;
                $plan[] = ['shot' => $shot, 'seconds' => round($seconds, 2), 'overlay' => $overlay];
            }

            $outputPath = self::outputPath($roomId);
            self::run($ffmpeg, self::arguments($plan, $outputPath['absolute']));

            if (!is_file($outputPath['absolute']) || filesize($outputPath['absolute']) < 1024) {
                @unlink($outputPath['absolute']);
                throw new RuntimeException('ffmpeg finished but wrote no usable video file.');
            }

            return $outputPath['site'];
        } finally {
            foreach ($overlays as $overlay) {
                @unlink($overlay);
            }
            @rmdir($workDir);
        }
    }

    /**
     * Total runtime of a script once the cross-fades have eaten their overlap —
     * what the studio shows before anyone waits on a render.
     *
     * @param array<int, array{seconds:float}> $scenes
     */
    public static function duration(array $scenes): float
    {
        if ($scenes === []) {
            return 0.0;
        }

        return round(
            max(0.5, array_sum(array_column($scenes, 'seconds')) - self::TRANSITION * (count($scenes) - 1)),
            1
        );
    }

    /**
     * The room's own media, cover photo first, with a tour clip promoted to
     * second place when one exists — motion early is what keeps a reel being
     * watched. Files that are recorded but missing on disk are skipped.
     *
     * @return array<int, array{kind:string, file:string, duration:float}>
     */
    private static function shotsFor(int $roomId): array
    {
        $onDisk = static function (string $sitePath): ?string {
            $file = APP_ROOT . '/public' . $sitePath;

            return is_file($file) ? $file : null;
        };

        $photos = [];
        foreach (Room::photoUrls($roomId) as $path) {
            // SVG placeholders are line art, not photography, and ffmpeg cannot
            // decode them anyway — a reel of them would advertise nothing.
            if (str_ends_with(strtolower($path), '.svg')) {
                continue;
            }
            if (($file = $onDisk($path)) !== null) {
                $photos[] = ['kind' => 'photo', 'file' => $file, 'duration' => 0.0];
            }
        }

        $clips = [];
        foreach (Room::videoUrls($roomId) as $path) {
            if (($file = $onDisk($path)) !== null) {
                $duration = self::clipDuration($file);
                if ($duration > 1.5) {
                    $clips[] = ['kind' => 'clip', 'file' => $file, 'duration' => $duration];
                }
            }
        }

        if ($photos === []) {
            return $clips;
        }
        if ($clips === []) {
            return $photos;
        }

        return array_merge([$photos[0], $clips[0]], array_slice($photos, 1), array_slice($clips, 1));
    }

    /** Seconds of a tour clip, 0.0 when ffprobe cannot read it. */
    private static function clipDuration(string $file): float
    {
        $probe = self::binary();
        if ($probe === null) {
            return 0.0;
        }
        // ffprobe ships beside ffmpeg in every distribution of it.
        $ffprobe = preg_replace('/ffmpeg(\.exe)?$/i', 'ffprobe$1', $probe);

        exec(
            escapeshellarg((string) $ffprobe) . ' -v error -show_entries format=duration -of csv=p=0 '
            . escapeshellarg($file) . ' 2>&1',
            $output,
            $code
        );

        return $code === 0 ? (float) trim(implode('', $output)) : 0.0;
    }

    /**
     * The ffmpeg argument list: one input per scene, one input per text layer,
     * a silent audio bed, then the filter graph that pans, overlays and fades.
     *
     * @param array<int, array{shot:array{kind:string, file:string, duration:float}, seconds:float, overlay:string}> $plan
     * @return string[]
     */
    private static function arguments(array $plan, string $outputPath): array
    {
        $sceneCount = count($plan);
        $total = self::duration(array_map(static fn (array $step): array => ['seconds' => $step['seconds']], $plan));

        $args = ['-y', '-hide_banner', '-loglevel', 'error'];
        foreach ($plan as $step) {
            if ($step['shot']['kind'] === 'clip') {
                $args = array_merge($args, ['-ss', '0', '-t', (string) $step['seconds'], '-i', $step['shot']['file']]);
            } else {
                $args = array_merge($args, ['-loop', '1', '-t', (string) $step['seconds'], '-i', $step['shot']['file']]);
            }
        }
        foreach ($plan as $step) {
            $args = array_merge($args, ['-loop', '1', '-t', (string) $step['seconds'], '-i', $step['overlay']]);
        }
        $args = array_merge($args, ['-f', 'lavfi', '-t', (string) $total, '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100']);

        $filters = [];
        foreach ($plan as $i => $step) {
            $fill = sprintf(
                'scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d',
                CONTENT_VIDEO_WIDTH,
                CONTENT_VIDEO_HEIGHT,
                CONTENT_VIDEO_WIDTH,
                CONTENT_VIDEO_HEIGHT
            );
            // A still needs the slow push to stop reading as a slideshow; a
            // tour clip already moves, so it only gets normalised.
            $motion = $step['shot']['kind'] === 'clip'
                ? sprintf('fps=%d,setsar=1', CONTENT_VIDEO_FPS)
                : sprintf(
                    "zoompan=z='min(zoom+0.0009,1.12)':d=%d:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':s=%dx%d:fps=%d,setsar=1",
                    (int) round($step['seconds'] * CONTENT_VIDEO_FPS),
                    CONTENT_VIDEO_WIDTH,
                    CONTENT_VIDEO_HEIGHT,
                    CONTENT_VIDEO_FPS
                );

            $filters[] = sprintf('[%d:v]%s,%s[m%d]', $i, $fill, $motion, $i);
            $filters[] = sprintf('[%d:v]scale=%d:%d[o%d]', $sceneCount + $i, CONTENT_VIDEO_WIDTH, CONTENT_VIDEO_HEIGHT, $i);
            $filters[] = sprintf('[m%d][o%d]overlay=0:0:format=auto,format=yuv420p[v%d]', $i, $i, $i);
        }

        // Chain the cross-fades: each offset is where the outgoing scene has
        // TRANSITION seconds left, minus the overlap already spent.
        $current = '[v0]';
        $elapsed = $plan[0]['seconds'];
        for ($i = 1; $i < $sceneCount; $i++) {
            $offset = round($elapsed - self::TRANSITION, 2);
            $label = $i === $sceneCount - 1 ? '[vout]' : "[x$i]";
            $filters[] = sprintf('%s[v%d]xfade=transition=fade:duration=%s:offset=%s%s', $current, $i, self::TRANSITION, $offset, $label);
            $current = $label;
            $elapsed = $offset + $plan[$i]['seconds'];
        }
        $videoLabel = $sceneCount === 1 ? '[v0]' : '[vout]';

        return array_merge($args, [
            '-filter_complex', implode(';', $filters),
            '-map', $videoLabel,
            '-map', (string) (2 * $sceneCount) . ':a',
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-crf', '23',
            '-profile:v', 'high',
            '-pix_fmt', 'yuv420p',
            '-r', (string) CONTENT_VIDEO_FPS,
            '-c:a', 'aac',
            '-b:a', '96k',
            '-shortest',
            '-movflags', '+faststart',
            $outputPath,
        ]);
    }

    /** @param string[] $args */
    private static function run(string $ffmpeg, array $args): void
    {
        $command = escapeshellarg($ffmpeg) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';

        $output = [];
        exec($command, $output, $code);

        if ($code !== 0) {
            throw new RuntimeException('ffmpeg failed to render the promo video: ' . mb_substr(trim(implode(' ', $output)), 0, 300));
        }
    }

    /**
     * One scene's text layer: a bottom scrim so words stay readable over any
     * photo, the brand chip, the headline and its supporting line, and a
     * progress bar that tells a viewer how much reel is left.
     *
     * @param array{bold:string, regular:string} $fonts
     */
    private static function drawOverlay(
        string $file,
        array $fonts,
        string $headline,
        string $sub,
        bool $isCta,
        int $position,
        int $total,
        ?string $mascotPose = null
    ): void {
        $width = CONTENT_VIDEO_WIDTH;
        $height = CONTENT_VIDEO_HEIGHT;

        $canvas = imagecreatetruecolor($width, $height);
        imagesavealpha($canvas, true);
        imagealphablending($canvas, false);
        imagefilledrectangle($canvas, 0, 0, $width, $height, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);

        try {
            $white = imagecolorallocate($canvas, 255, 255, 255);
            $teal = imagecolorallocate($canvas, ...self::TEAL);
            $softWhite = imagecolorallocatealpha($canvas, 255, 255, 255, 30);

            if ($isCta) {
                // The end card earns a full wash: it is the one frame a viewer
                // is meant to read rather than look at.
                imagefilledrectangle($canvas, 0, 0, $width, $height, imagecolorallocatealpha($canvas, 12, 34, 45, 45));
            } else {
                $scrimTop = (int) ($height * 0.52);
                for ($y = $scrimTop; $y < $height; $y++) {
                    $progress = ($y - $scrimTop) / ($height - $scrimTop);
                    $alpha = (int) round(127 - 127 * min(1.0, $progress * 1.35) * 0.82);
                    imagefilledrectangle($canvas, 0, $y, $width, $y, imagecolorallocatealpha($canvas, ...array_merge(self::INK, [$alpha])));
                }
            }

            // Brand chip, top left — a teal pill with the wordmark in it.
            $chipHeight = 74;
            imagefilledrectangle($canvas, 64, 88, 64 + 214, 88 + $chipHeight, $teal);
            imagettftext($canvas, 34, 0, 96, 88 + 50, $white, $fonts['bold'], 'beLive');

            $margin = 72;
            $maxWidth = $width - $margin * 2;

            if ($isCta) {
                $lines = self::wrap($fonts['bold'], 74, $maxWidth, $headline);
                $blockTop = (int) ($height / 2) - count($lines) * 50;
                // The end card is the mascot's frame: it stands over the ask,
                // centred, at the size a sticker would be.
                MascotLibrary::stamp($canvas, $mascotPose, 560, (int) ($width / 2), $blockTop - 60, 'centre');
                $y = self::writeLines($canvas, $lines, $fonts['bold'], 74, 96, $margin, $blockTop, $white);
                if ($sub !== '') {
                    $y = self::writeLines($canvas, self::wrap($fonts['regular'], 44, $maxWidth, $sub), $fonts['regular'], 44, 62, $margin, $y + 34, $softWhite);
                }
                // The teal rule under an end card reads as a button edge.
                imagefilledrectangle($canvas, $margin, $y + 46, $margin + 260, $y + 56, $teal);
            } else {
                // On a room shot the mascot stands to the right, its feet on the
                // line where the scrim begins, clear of the words underneath.
                MascotLibrary::stamp($canvas, $mascotPose, 520, $width - 40, (int) ($height * 0.52) + 90, 'right');

                $headlineLines = self::wrap($fonts['bold'], 66, $maxWidth, $headline);
                $subLines = $sub !== '' ? self::wrap($fonts['regular'], 40, $maxWidth, $sub) : [];
                $blockHeight = count($headlineLines) * 86 + count($subLines) * 58;
                $blockTop = $height - 250 - $blockHeight;

                $y = self::writeLines($canvas, $headlineLines, $fonts['bold'], 66, 86, $margin, $blockTop, $white);
                if ($subLines !== []) {
                    self::writeLines($canvas, $subLines, $fonts['regular'], 40, 58, $margin, $y + 24, $softWhite);
                }
            }

            // Progress bar along the very bottom.
            $barY = $height - 34;
            imagefilledrectangle($canvas, $margin, $barY, $width - $margin, $barY + 8, imagecolorallocatealpha($canvas, 255, 255, 255, 90));
            $filled = (int) round(($width - $margin * 2) * ($position / max(1, $total)));
            imagefilledrectangle($canvas, $margin, $barY, $margin + $filled, $barY + 8, $teal);

            if (!imagepng($canvas, $file)) {
                throw new RuntimeException('The promo video text layer could not be written.');
            }
        } finally {
            imagedestroy($canvas);
        }
    }

    /**
     * Draw each line and return the y the block ended on, so the next block can
     * sit under it whatever the wrap did.
     *
     * @param \GdImage $canvas
     * @param string[] $lines
     */
    private static function writeLines($canvas, array $lines, string $font, float $size, int $lineHeight, int $x, int $y, int $color): int
    {
        foreach ($lines as $line) {
            $y += $lineHeight;
            imagettftext($canvas, $size, 0, $x, $y, $color, $font, $line);
        }

        return $y;
    }

    /**
     * Wrap on real glyph widths — a headline is only capped at 40 characters,
     * which at 66pt can still be wider than the frame.
     *
     * @return string[]
     */
    private static function wrap(string $font, float $size, int $maxWidth, string $text): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $line = '';

        foreach ($words as $word) {
            $candidate = $line === '' ? $word : "$line $word";
            $box = imagettfbbox($size, 0, $font, $candidate);
            if ($box !== false && ($box[2] - $box[0]) > $maxWidth && $line !== '') {
                $lines[] = $line;
                $line = $word;
                continue;
            }
            $line = $candidate;
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines === [] ? [''] : $lines;
    }

    /** @return array{bold:string, regular:string}|null */
    private static function fonts(): ?array
    {
        $bold = trim((string) ($_ENV['PROMO_FONT_BOLD'] ?? ''));
        $regular = trim((string) ($_ENV['PROMO_FONT_REGULAR'] ?? ''));
        if ($bold !== '' && is_file($bold)) {
            return ['bold' => $bold, 'regular' => is_file($regular) ? $regular : $bold];
        }

        foreach (self::FONT_CANDIDATES as [$boldCandidate, $regularCandidate]) {
            if (is_file($boldCandidate)) {
                return ['bold' => $boldCandidate, 'regular' => is_file($regularCandidate) ? $regularCandidate : $boldCandidate];
            }
        }

        return null;
    }

    private static function workDir(): string
    {
        $dir = sys_get_temp_dir() . '/belive_reel_' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('The promo video working folder could not be created.');
        }

        return $dir;
    }

    /** @return array{absolute:string, site:string} */
    private static function outputPath(int $roomId): array
    {
        $directory = APP_ROOT . '/public' . self::OUTPUT_DIR;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('The promo video folder could not be created.');
        }

        $filename = sprintf('room_%d_%s_%s.mp4', $roomId, date('Ymd_His'), bin2hex(random_bytes(4)));

        return ['absolute' => $directory . '/' . $filename, 'site' => self::OUTPUT_DIR . '/' . $filename];
    }
}
