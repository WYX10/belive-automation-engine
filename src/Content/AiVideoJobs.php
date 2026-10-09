<?php

declare(strict_types=1);

namespace App\Content;

use App\AI\Skills\CreateSkill;
use App\Core\Database;
use App\Core\Scheduler;
use App\Models\Room;
use RuntimeException;

/** Hosted generative video jobs; no GPU wait in a browser request. */
final class AiVideoJobs
{
    public static function setting(string $name, string $default = ''): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        return $value === false ? $default : (string) $value;
    }

    public static function enabled(): bool
    {
        return self::setting('AI_VIDEO_PROVIDER', 'illustrated') === 'huggingface';
    }

    public static function available(): bool
    {
        return self::enabled() && !filter_var(self::setting('MOCK_AI', 'false'), FILTER_VALIDATE_BOOLEAN)
            && RoomVideoComposer::isAvailable()
            && is_executable(APP_ROOT . '/ai_video/huggingface/.venv/bin/python')
            && function_exists('proc_open');
    }

    public static function enqueue(array $room, string $platform, ?string $brief): int
    {
        if (!self::available()) {
            throw new RuntimeException('Install the AI video client and media tools, then enable Hugging Face video in hosting settings.');
        }
        if (!in_array($platform, CONTENT_PLATFORMS, true)) {
            throw new RuntimeException('Unsupported video platform.');
        }
        $script = CreateSkill::videoPromo($room, $platform, $brief, 1);
        $frame = self::inputFrame($room, $brief);
        try {
            $id = Database::insert(
                'INSERT INTO ai_video_jobs (room_id, platform, payload, input_path) VALUES (?, ?, ?, ?)',
                [(int) $room['id'], $platform, json_encode($script, JSON_THROW_ON_ERROR), $frame]
            );
        } catch (\Throwable $e) {
            @unlink($frame);
            throw $e;
        }
        self::dispatch();
        return $id;
    }

    public static function recent(): array
    {
        try {
            return Database::run('SELECT id, room_id, platform, status, post_id, error_code, created_at FROM ai_video_jobs ORDER BY id DESC LIMIT 10')->fetchAll();
        } catch (\Throwable) {
            return []; // Existing installations can display the studio before migration.
        }
    }

    public static function dispatch(): void
    {
        if (!self::available()) {
            return;
        }
        try {
            if (Database::run("SELECT id FROM ai_video_jobs WHERE status = 'queued' LIMIT 1")->fetchColumn()) {
                Scheduler::spawn('cron/ai_video_worker.php', ['--once']);
            }
        } catch (\Throwable) {
            // No job submission or paid fallback on a migration/runtime failure.
        }
    }

    public static function processNext(?callable $generate = null): bool
    {
        if ($generate === null && !self::available()) {
            return false;
        }
        $pdo = Database::pdo();
        if (!Database::acquireLock('belive_ai_video_generation', 0, $pdo)) {
            return false;
        }
        $job = null;
        try {
            $job = Database::run("SELECT * FROM ai_video_jobs WHERE status = 'queued' ORDER BY id LIMIT 1")->fetch();
            if (!$job) {
                return false;
            }
            if (Database::run("UPDATE ai_video_jobs SET status = 'running', started_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'queued'", [$job['id']])->rowCount() !== 1) {
                return false;
            }
            $payload = json_decode($job['payload'], true, 512, JSON_THROW_ON_ERROR);
            $work = self::directory() . '/job_' . (int) $job['id'];
            if (!is_dir($work) && !mkdir($work, 0700)) {
                throw new RuntimeException('output');
            }
            $room = Room::find((int) $job['room_id']);
            if ($room === null) {
                throw new RuntimeException('input');
            }
            $prompt = self::prompt($payload['scenes']);
            $result = $generate !== null ? $generate($job, $work, $prompt)
                : self::generate(['image' => $job['input_path'], 'cache' => $work, 'prompt' => $prompt]);
            if (empty($result['ok'])) {
                throw new RuntimeException($result['error'] ?? 'provider');
            }
            $clip = realpath((string) ($result['video'] ?? ''));
            if ($clip === false || !str_starts_with($clip, realpath($work) . DIRECTORY_SEPARATOR)
                || strtolower(pathinfo($clip, PATHINFO_EXTENSION)) !== 'mp4' || filesize($clip) < 1024) {
                throw new RuntimeException('output');
            }
            // A single short generated shot fits a limited free GPU allowance.
            // The rest of the marketing reel uses verified original room media.
            $scenes = array_merge(array_slice($payload['scenes'], 0, 2), [PromoVideoDrafter::endCard($room)]);
            $scenes[0]['seconds'] = min(3.3, RoomVideoComposer::clipDuration($clip));
            if ($scenes[0]['seconds'] < 2.5) {
                throw new RuntimeException('output');
            }
            $meta = null;
            $url = RoomVideoComposer::render($room, $scenes, $meta, null,
                [['kind' => 'clip', 'file' => $clip, 'generated_presenter' => true]]);
            $renderedScenes = $meta['scenes'];
            unset($meta['scenes']);
            $meta = array_merge($meta, ['style' => 'generative_mascot_tour', 'ai_generated_footage' => true,
                'video_model' => $result['model'], 'video_provider' => 'huggingface_space',
                'space' => $result['space'], 'generated_shots' => 1, 'review_required' => true]);
            $pdo->beginTransaction();
            try {
                $post = Database::insert(
                    'INSERT INTO content_posts (platform, media_kind, room_id, caption, status, generated_by_model, generated_via, video_url, video_script, creative_meta) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$job['platform'], 'video', $job['room_id'], $payload['caption'] . "\nAI-generated mascot animation; verify the room against its listing photos.",
                        'draft', $payload['model'] . ' + ' . $result['model'], 'manual', $url,
                        json_encode($renderedScenes, JSON_THROW_ON_ERROR), json_encode($meta, JSON_THROW_ON_ERROR)]
                );
                Database::run("UPDATE ai_video_jobs SET status = 'completed', post_id = ?, completed_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'running'", [$post, $job['id']]);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                @unlink(APP_ROOT . '/public' . $url);
                throw $e;
            }
            return true;
        } catch (\Throwable $e) {
            if ($job) {
                $known = ['quota', 'provider', 'paid_account', 'endpoint', 'arguments', 'output', 'input', 'timeout', 'space'];
                $code = in_array($e->getMessage(), $known, true) ? $e->getMessage() : 'generation';
                Database::run("UPDATE ai_video_jobs SET status = 'failed', error_code = ?, completed_at = UTC_TIMESTAMP() WHERE id = ?", [$code, $job['id']]);
            }
            return false;
        } finally {
            Database::releaseLock('belive_ai_video_generation', $pdo);
        }
    }

    public static function errorMessage(string $code): string
    {
        return match ($code) {
            'quota' => 'The free GPU allowance is exhausted. Try a new request after it resets.',
            'paid_account' => 'Use an anonymous session or a free personal Hugging Face account. Paid or unverified account plans are blocked.',
            'endpoint', 'arguments' => 'The Space API has changed. IT must check the image-to-video endpoint before resubmitting.',
            'timeout' => 'Generation timed out. Its remote outcome is unknown; inspect the Space before submitting again.',
            'output' => 'No valid generated video was returned.',
            default => 'AI video generation failed. Check the runtime and Space availability; no alternate paid provider was called.',
        };
    }

    public static function prompt(array $scenes): string
    {
        $action = ($scenes[0]['presenter_action'] ?? 'wave') === 'point' ? 'points toward the bed and room' : 'waves, then gestures toward the room';
        return "Animate the supplied room photograph and BeLive cartoon mascot as one continuous cinematic shot. "
            . "The mascot comes alive and $action, introducing the room. Keep the mascot design and colours consistent. "
            . "Slow gentle camera reveal, subtle natural movement, clear stable furniture and room layout. "
            . "Preserve the original room, windows, walls and furnishings. Do not add amenities, people or larger spaces. "
            . "No captions, written words, logos or prices; the application adds verified text afterwards.";
    }

    private static function directory(): string
    {
        $dir = APP_ROOT . '/storage/ai_video';
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            throw new RuntimeException('input');
        }
        return $dir;
    }

    private static function inputFrame(array $room, ?string $brief): string
    {
        $root = realpath(APP_ROOT . '/public');
        foreach (Room::photoUrls((int) $room['id']) as $photo) {
            if (str_contains($photo, '/placeholders/') || preg_match('~(?:^|/)placeholder\.[a-z]+$~i', $photo)) {
                continue;
            }
            $path = realpath(APP_ROOT . '/public' . $photo);
            if (!$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $original = @imagecreatefromstring((string) file_get_contents($path));
            if ($original === false) {
                continue;
            }
            $image = $original;
            $resized = null;
            try {
                $edge = max(imagesx($original), imagesy($original));
                if ($edge > 1280) {
                    $scale = 1280 / $edge;
                    $resized = imagescale($original, (int) round(imagesx($original) * $scale), (int) round(imagesy($original) * $scale));
                    if ($resized === false) {
                        throw new RuntimeException('input');
                    }
                }
                $polish = \App\Properties\RoomPhotoEnhancer::polish($resized ?? $original, $brief, (int) $room['id']);
                $image = $polish['image'];
                $height = imagesy($image);
                MascotLibrary::place($image, 'waving', (int) ($height * 0.35), (int) (imagesx($image) * 0.18), (int) ($height * 0.9));
                $out = self::directory() . '/input_' . bin2hex(random_bytes(12)) . '.png';
                if (!imagepng($image, $out)) {
                    throw new RuntimeException('input');
                }
                chmod($out, 0600);
                return $out;
            } finally {
                if ($image !== $original && $image !== $resized) {
                    imagedestroy($image);
                }
                if ($resized instanceof \GdImage) imagedestroy($resized);
                imagedestroy($original);
            }
        }
        throw new RuntimeException('A generative video needs a real uploaded room photo; placeholder artwork is not suitable.');
    }

    private static function generate(array $payload): array
    {
        $env = [];
        foreach (['PATH', 'LANG', 'LC_ALL', 'TMPDIR', 'HOME', 'SSL_CERT_FILE', 'SSL_CERT_DIR', 'HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY'] as $name) {
            $value = getenv($name);
            if ($value !== false) $env[$name] = $value;
        }
        foreach (['HF_VIDEO_SPACE', 'HF_VIDEO_API_NAME', 'HF_VIDEO_TOKEN'] as $name) {
            $env[$name] = self::setting($name, $name === 'HF_VIDEO_SPACE' ? 'Wan-AI/Wan-2.2-5B' : '');
        }
        $pipes = [];
        $dir = APP_ROOT . '/ai_video/huggingface';
        $proc = proc_open([$dir . '/.venv/bin/python', $dir . '/client.py'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, APP_ROOT, $env);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'provider'];
        }
        fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $deadline = microtime(true) + 660;
        do {
            $out .= stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]); // Drain without logging provider secrets or image paths.
            $state = proc_get_status($proc);
            if (!$state['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc);
                $out = '{"ok":false,"error":"timeout"}';
                break;
            }
            usleep(100000);
        } while (true);
        $out .= stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return json_decode($out, true) ?: ['ok' => false, 'error' => 'provider'];
    }
}
