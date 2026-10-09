<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();
require APP_ROOT . '/config/constants.php';
date_default_timezone_set('Asia/Kuala_Lumpur');
@set_time_limit(0);
do {
    try {
        \App\Content\AiVideoJobs::processNext();
    } catch (Throwable) {
        error_log('[ai video] Worker needs configuration/schema review.');
    }
    if (in_array('--once', $argv, true)) {
        break;
    }
    sleep(15);
} while (true);
