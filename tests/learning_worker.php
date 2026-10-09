<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();
require APP_ROOT . '/config/constants.php';
$config = require APP_ROOT . '/config/database.php';
if (($argv[1] ?? '') !== $config['name'] . '_test') {
    exit("Worker is restricted to the configured throwaway test database.\n");
}
$_ENV['DB_NAME'] = $argv[1];
$_ENV['MOCK_AI'] = 'true';
if (($argv[2] ?? '') === 'lesson') {
    $id = App\AI\Memory\MemoryStore::saveRule('Concurrent Area', 'strategy', 'Verify the current room before offering it.', null, 0.6, 'concurrent_verify_room');
    echo json_encode(['memory_id' => $id]);
} elseif (($argv[2] ?? '') === 'feedback') {
    $id = App\AI\Memory\FeedbackCollector::adminFlag((int) ($argv[3] ?? 0), 'missed_intent', 'Answer the question the tenant asked first.');
    App\AI\Memory\LearningEngine::processFeedback($id);
    echo json_encode(['feedback_id' => $id, 'memory_id' => (int) App\Core\Database::run('SELECT memory_id FROM ai_feedback WHERE id = ?', [$id])->fetchColumn()]);
} else {
    exit(1);
}
