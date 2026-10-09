<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit("Run from the command line.\n");
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();
require APP_ROOT . '/config/constants.php';
date_default_timezone_set('Asia/Kuala_Lumpur');
$id = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$legacySpace = null;
if (count($argv) === 4 && $argv[2] === '--legacy-space') $legacySpace = $argv[3];
elseif (count($argv) !== 2) $id = false;
if ($id === false) exit("Usage: php database/ai_video_recover.php <job-id> [--legacy-space <original-space>]\n");
try {
    $postId = App\Content\AiVideoJobs::recover($id, $legacySpace);
    echo json_encode(['ok' => true, 'job_id' => $id, 'post_id' => $postId, 'gpu_requested' => false], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $e) {
    // Recover() uses fixed local error messages. Do not expose raw database errors or credentials.
    $message = $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage()
        : 'Could not recover the video. Check application database/runtime configuration.';
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
