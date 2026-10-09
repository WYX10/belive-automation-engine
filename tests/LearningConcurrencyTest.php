<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\Interaction;
use App\Models\Lead;

$runLearningWorkers = static function (string $mode, ?int $interactionId = null): array {
    $database = (string) Database::run('SELECT DATABASE()')->fetchColumn();
    $workers = [];
    for ($n = 0; $n < 6; $n++) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), __DIR__ . '/learning_worker.php', $database, $mode, (string) ($interactionId ?? '')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        check('concurrent ' . $mode . ' worker completed', $status === 0, $err . $out);
        $results[] = json_decode($out, true) ?? [];
    }
    return $results;
};
$lessonResults = $runLearningWorkers('lesson');
check('six concurrent lesson writes return one memory ID', count(array_unique(array_column($lessonResults, 'memory_id'))) === 1);
check('concurrent lesson writes store exactly one row', (int) Database::run("SELECT COUNT(*) FROM ai_learned_memory WHERE context_tag = 'Concurrent Area'")->fetchColumn() === 1);
$concurrentLead = Lead::findOrCreate('60115554001', 'Concurrent Learning', 'whatsapp');
$outbound = Interaction::create(['lead_id' => $concurrentLead['id'], 'phase' => 'conversion', 'skill' => 'automate', 'model_used' => 'test', 'direction' => 'outbound', 'message_out' => 'An unhelpful answer.']);
$feedbackResults = $runLearningWorkers('feedback', $outbound);
check('six concurrent flags create one feedback event', count(array_unique(array_column($feedbackResults, 'feedback_id'))) === 1);
check('six concurrent learning calls reuse one resulting lesson', count(array_unique(array_column($feedbackResults, 'memory_id'))) === 1);
$concurrentMemory = (int) ($feedbackResults[0]['memory_id'] ?? 0);
check('repeated concurrent processing does not reinforce the same evidence twice', $concurrentMemory > 0
    && (int) Database::run('SELECT times_reinforced FROM ai_learned_memory WHERE id = ?', [$concurrentMemory])->fetchColumn() === 0);
