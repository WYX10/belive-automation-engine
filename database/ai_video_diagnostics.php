<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit("Run from the command line.\n");
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();
if (($argv[1] ?? '') === '--check') {
    try {
        $probe = new ReflectionMethod(App\Content\AiVideoJobs::class, 'generate');
        echo json_encode([
            'token_configured' => App\Content\AiVideoJobs::setting('HF_VIDEO_TOKEN') !== '',
            'check' => $probe->invoke(null, ['check' => true]),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    } catch (Throwable) {
        fwrite(STDERR, "Could not run the video connection check. Check application runtime configuration.\n");
        exit(1);
    }
}
$id = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) exit("Usage: php database/ai_video_diagnostics.php <job-id>|--check\n");
try {
    echo json_encode(App\Content\AiVideoJobs::diagnostics($id), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "Could not read video diagnostics. Check application database/runtime configuration.\n");
    exit(1);
}
