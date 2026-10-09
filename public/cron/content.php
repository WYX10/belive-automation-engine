<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Content\ContentPublishWorker;
use App\Core\Scheduler;

header('Content-Type: application/json');
$expected = (string) ($_ENV['CRON_TOKEN'] ?? '');
$given = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$given = str_starts_with($given, 'Bearer ') ? substr($given, 7) : '';
if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not found']);
    return;
}
@set_time_limit(0);
try {
    $result = ContentPublishWorker::runDue();
    $draft = Scheduler::tickAutoDraft();
    echo json_encode(['ok' => true, 'delivery' => $result, 'draft_dispatch' => $draft]);
} catch (Throwable $e) {
    error_log('[content tick] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Content scheduler failed; see the application log.']);
}
