<?php

declare(strict_types=1);

namespace App\Content;

use App\Models\Room;
use App\Properties\RoomPhotoEnhancer;
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
 * layer. An independent mascot enters, gestures and changes pose as it narrates
 * the tour. eSpeak NG provides optional offline speech; captions remain available
 * on hosts without it. Full-width room reveals preserve the real layout.
 *
 * GD, fonts and ffmpeg are required; offline speech is optional:
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
        return function_exists('imagecreatetruecolor') && self::fonts() !== null && MascotLibrary::isAvailable() && self::binary() !== null;
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
    public static function render(array $room, array $scenes, ?array &$details = null, ?string $brief = null): string
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
        $voiceAvailable = MascotNarrator::isAvailable();
        try {
            // Pair each scene with a shot, reusing shots when the script is
            // longer than the gallery, and clamp a clip scene to the clip.
            $plan = [];
            $polished = [];
            foreach (array_values($scenes) as $i => $scene) {
                $shot = $shots[$i % count($shots)];
                $seconds = max(2.5, min(6.0, (float) $scene['seconds']));
                if ($shot['kind'] === 'photo') {
                    if (!isset($polished[$shot['file']])) {
                        $photo = @imagecreatefromstring((string) file_get_contents($shot['file']));
                        if ($photo === false) {
                            throw new RuntimeException('The room photo could not be decoded.');
                        }
                        try {
                            $result = RoomPhotoEnhancer::polish($photo, $brief, $roomId);
                            $copy = $workDir . '/room_' . count($polished) . '.jpg';
                            imagejpeg($result['image'], $copy, 92);
                            if ($result['image'] !== $photo) {
                                imagedestroy($result['image']);
                            }
                            $polished[$shot['file']] = $copy;
                        } finally {
                            imagedestroy($photo);
                        }
                    }
                    $shot['file'] = $polished[$shot['file']];
                }

                $isCta = (bool) ($scene['cta'] ?? false);
                $action = $scene['presenter_action'] ?? ($i === 0 ? 'wave' : 'point');
                $narration = trim((string) ($scene['narration'] ?? ''));
                if ($narration === '') {
                    $narration = $i === 0 ? "Hi, I'm beLive. Let me show you this room."
                        : trim($scene['headline'] . '. ' . ($scene['sub'] ?? ''));
                }
                $voice = $voiceAvailable ? MascotNarrator::speak($narration, $workDir, $i) : null;
                $pose = $isCta ? 'megaphone' : ($action === 'wave' ? 'waving' : 'wink-point');
                $reaction = $isCta ? 'cheering' : MascotLibrary::forScene(max(1, $i), trim($scene['headline'] . ' ' . ($scene['sub'] ?? '')));
                $sprites = [];
                foreach ([$pose, $reaction] as $j => $name) {
                    $sprite = MascotLibrary::cutout($name, $isCta ? 460 : 430);
                    if ($sprite === null) {
                        throw new RuntimeException('The mascot artwork is missing — restore assets/img/mascot before rendering a guided tour.');
                    }
                    $file = $workDir . '/host_' . $i . '_' . $j . '.png';
                    imagepng($sprite, $file);
                    imagedestroy($sprite);
                    $sprites[] = $file;
                }
                $overlay = $workDir . '/overlay_' . $i . '.png';
                self::drawOverlay(
                    $overlay,
                    $fonts,
                    (string) $scene['headline'],
                    (string) ($scene['sub'] ?? ''),
                    $isCta,
                    $i + 1,
                    count($scenes),
                    $narration
                );
                $scene['seconds'] = round($seconds, 2);
                $scene['narration'] = $narration;
                $scene['presenter_action'] = $action;
                $scene['camera'] ??= $i === 0 ? 'reveal' : ($i % 2 ? 'pan_right' : 'pan_left');
                $plan[] = ['shot' => $shot, 'seconds' => $scene['seconds'], 'overlay' => $overlay,
                    'sprites' => $sprites, 'voice' => $voice, 'scene' => $scene];
            }

            $outputPath = self::outputPath($roomId);
            self::run($ffmpeg, self::arguments($plan, $outputPath['absolute']));

            if (!is_file($outputPath['absolute']) || filesize($outputPath['absolute']) < 1024) {
                @unlink($outputPath['absolute']);
                throw new RuntimeException('ffmpeg finished but wrote no usable video file.');
            }

            $details = ['style' => 'mascot_guided_tour', 'animated' => true,
                'narrated' => count(array_filter(array_column($plan, 'voice'))) === count($plan),
                'voice' => $voiceAvailable ? 'espeak-ng/en-us' : 'captions only',
                'photos_polished' => count($polished), 'scenes' => array_column($plan, 'scene')];
            return $outputPath['site'];
        } catch (\Throwable $e) {
            if (isset($outputPath)) {
                @unlink($outputPath['absolute']);
            }
            throw $e;
        } finally {
            foreach (glob($workDir . '/*') ?: [] as $artifact) {
                @unlink($artifact);
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
        $count = count($plan);
        $total = self::duration($plan);
        $args = ['-y', '-hide_banner', '-loglevel', 'error', '-filter_complex_threads', '1'];
        foreach ($plan as $step) {
            $args = array_merge($args, $step['shot']['kind'] === 'clip'
                ? ['-stream_loop', '-1', '-t', (string) $step['seconds'], '-i', $step['shot']['file']]
                : ['-loop', '1', '-framerate', '30', '-t', (string) $step['seconds'], '-i', $step['shot']['file']]);
        }
        foreach ($plan as $step) {
            $args = array_merge($args, ['-loop', '1', '-framerate', '30', '-t', (string) $step['seconds'], '-i', $step['overlay']]);
        }
        foreach ($plan as $step) {
            foreach ($step['sprites'] as $sprite) {
                $args = array_merge($args, ['-loop', '1', '-framerate', '30', '-t', (string) $step['seconds'], '-i', $sprite]);
            }
        }
        $audioIndex = 4 * $count;
        $args = array_merge($args, ['-f', 'lavfi', '-t', (string) $total, '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100']);
        $nextInput = $audioIndex + 1;
        $filters = [];
        $audioLabels = ['[' . $audioIndex . ':a]'];
        $elapsed = 0.0;
        foreach ($plan as $i => $step) {
            $frames = max(1, (int) round($step['seconds'] * 30));
            $camera = $step['scene']['camera'];
            $zoom = $camera === 'detail' ? "1.05+0.04*on/$frames" : "1.0+0.025*on/$frames";
            $pan = match ($camera) {
                'pan_left' => "(iw-iw/zoom)*(1-on/$frames)",
                'pan_right' => "(iw-iw/zoom)*on/$frames",
                default => 'iw/2-iw/zoom/2',
            };
            $filters[] = "[$i:v]fps=30,trim=duration={$step['seconds']},setpts=PTS-STARTPTS,split=2[b$i][f$i]";
            $filters[] = "[b$i]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,gblur=sigma=24,eq=brightness=-0.13:saturation=0.75,setsar=1[bg$i]";
            // Show the whole room inside the portrait canvas instead of
            // discarding both sides of a landscape upload with a centre crop.
            $filters[] = "[f$i]scale=1080:1180:force_original_aspect_ratio=decrease,format=rgba,pad=1080:1180:(ow-iw)/2:(oh-ih)/2:color=black@0,zoompan=z='$zoom':x='$pan':y='ih/2-ih/zoom/2':d=1:s=1080x1180:fps=30,setsar=1[fg$i]";
            $filters[] = "[bg$i][fg$i]overlay=0:210:format=auto[room$i]";
            $filters[] = '[' . ($count + $i) . ":v]format=rgba[o$i]";
            $filters[] = "[room$i][o$i]overlay=0:0:format=auto[card$i]";

            $cta = !empty($step['scene']['cta']);
            $x = $cta ? '(W-w)/2' : '84';
            $baseY = $cta ? 350 : 1070;
            $entry = $cta ? $x : 'if(lt(t,0.6),-w+(84+w)*(1-pow(1-t/0.6,3)),84)';
            $bounce = $step['scene']['presenter_action'] === 'celebrate' ? '12*abs(sin(t*5))' : '5*sin(t*4)';
            $switch = round(min(1.5, $step['seconds'] * 0.4), 2);
            foreach ([0, 1] as $j) {
                $input = 2 * $count + 2 * $i + $j;
                $angle = $j === 0 ? '0.025*sin(t*5)' : '0.018*sin(t*4)';
                $filters[] = "[$input:v]format=rgba,rotate='$angle':c=none:ow=rotw(0.03):oh=roth(0.03)[host{$i}_$j]";
            }
            $filters[] = "[card$i][host{$i}_0]overlay=x='$entry':y='$baseY-$bounce':enable='lt(t,$switch)':format=auto[first$i]";
            $filters[] = "[first$i][host{$i}_1]overlay=x='$x':y='$baseY-$bounce':enable='gte(t,$switch)':format=auto,format=yuv420p,setpts=PTS-STARTPTS,fps=30[v$i]";
            if ($step['voice'] !== null) {
                $voiceIndex = $nextInput++;
                $args = array_merge($args, ['-i', $step['voice']['file']]);
                $window = max(1.0, $step['seconds'] - self::TRANSITION - 0.35);
                $speed = max(1.0, min(2.0, $step['voice']['duration'] / $window));
                $delay = (int) round(($elapsed + 0.15) * 1000);
                $filters[] = "[$voiceIndex:a]aresample=44100,atempo=$speed,atrim=duration=$window,afade=t=out:st=" . max(0, $window - 0.08)
                    . ":d=0.08,adelay={$delay}|{$delay}[voice$i]";
                $audioLabels[] = "[voice$i]";
            }
            $elapsed += $step['seconds'] - self::TRANSITION;
        }
        $current = '[v0]';
        $elapsed = $plan[0]['seconds'];
        for ($i = 1; $i < $count; $i++) {
            $offset = round($elapsed - self::TRANSITION, 2);
            $label = "[fade$i]";
            $filters[] = "$current" . "[v$i]xfade=transition=fade:duration=0.5:offset=$offset,fps=30$label";
            $current = $label;
            $elapsed = $offset + $plan[$i]['seconds'];
        }
        $filters[] = implode('', $audioLabels) . 'amix=inputs=' . count($audioLabels)
            . ":normalize=0:duration=longest,alimiter=limit=0.92,atrim=duration={$total}[aout]";

        return array_merge($args, ['-filter_complex', implode(';', $filters), '-map', $current, '-map', '[aout]',
            '-c:v', 'libx264', '-threads', '2', '-preset', 'veryfast', '-crf', '23', '-profile:v', 'high',
            '-pix_fmt', 'yuv420p', '-r', '30', '-c:a', 'aac', '-b:a', '128k', '-t', (string) $total,
            '-movflags', '+faststart', $outputPath]);
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
        ?string $narration = null
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
            $softWhite = imagecolorallocate($canvas, 226, 237, 238);
            if ($isCta) {
                imagefilledrectangle($canvas, 0, 0, $width, $height, imagecolorallocatealpha($canvas, 12, 34, 45, 30));
            } else {
                for ($y = 1390; $y < $height; $y++) {
                    $alpha = (int) round(127 - 105 * min(1, ($y - 1390) / 300));
                    imageline($canvas, 0, $y, $width, $y, imagecolorallocatealpha($canvas, 15, 28, 36, $alpha));
                }
                // A caption bubble beside the independently animated host.
                $bubble = imagecolorallocatealpha($canvas, 244, 253, 252, 5);
                imagefilledrectangle($canvas, 450, 1180, 996, 1380, $bubble);
                imagefilledpolygon($canvas, [450, 1260, 420, 1290, 450, 1320], $bubble);
                self::writeLines($canvas, array_slice(self::wrap($fonts['regular'], 28, 470, (string) $narration), 0, 3),
                    $fonts['regular'], 28, 44, 482, 1192, imagecolorallocate($canvas, 26, 47, 54));
            }
            imagefilledrectangle($canvas, 64, 88, 278, 162, $teal);
            imagettftext($canvas, 34, 0, 96, 138, $white, $fonts['bold'], 'beLive');
            imagettftext($canvas, 24, 0, 310, 136, $white, $fonts['regular'], $isCta ? 'LET\'S MEET' : 'YOUR ROOM TOUR');
            $margin = 72;
            $lines = self::wrap($fonts['bold'], 60, $width - 2 * $margin, $headline);
            $top = $isCta ? 880 : 1510;
            $y = self::writeLines($canvas, array_slice($lines, 0, 2), $fonts['bold'], 60, 80, $margin, $top, $white);
            if ($sub !== '') {
                self::writeLines($canvas, array_slice(self::wrap($fonts['regular'], 35, $width - 2 * $margin, $sub), 0, 2),
                    $fonts['regular'], 35, 52, $margin, $y + 18, $softWhite);
            }
            imagefilledrectangle($canvas, $margin, $height - 134, $width - $margin, $height - 126,
                imagecolorallocatealpha($canvas, 255, 255, 255, 85));
            imagefilledrectangle($canvas, $margin, $height - 134,
                $margin + (int) (($width - 2 * $margin) * $position / max(1, $total)), $height - 126, $teal);
            if (!imagepng($canvas, $file)) {
                throw new RuntimeException('The tour caption layer could not be written.');
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
    public static function fonts(): ?array
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
