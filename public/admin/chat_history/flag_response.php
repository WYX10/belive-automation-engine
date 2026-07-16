<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

/**
 * The explicit feedback path: admin flags a wrong reply → FeedbackCollector
 * records it → LearningEngine distills a rule SYNCHRONOUSLY, so the very next
 * test message already shows the corrected behaviour (on-stage self-learning
 * proof for the judges).
 */

use App\AI\Memory\FeedbackCollector;
use App\AI\Memory\LearningEngine;
use App\Core\Auth;
use App\Models\LearnedMemory;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();
Auth::requireCsrf();

$interactionId = (int) ($_POST['interaction_id'] ?? 0);
$leadId = (int) ($_POST['lead_id'] ?? 0);
$errorType = $_POST['error_type'] ?? 'missed_intent';
$comment = trim($_POST['comment'] ?? '');

if ($interactionId <= 0 || $comment === '') {
    set_flash('danger', 'Pick the reply and say what was wrong.');
    header('Location: /admin/chat_history' . ($leadId ? "?lead_id=$leadId" : ''));
    exit;
}

$feedbackId = FeedbackCollector::adminFlag($interactionId, $errorType, $comment);

try {
    $memoryId = LearningEngine::processFeedback($feedbackId);
} catch (\Throwable $e) {
    $memoryId = null;
    set_flash('danger', 'Flag recorded, but rule distillation failed: ' . $e->getMessage()
        . ' — the batch learning job will retry it.');
}

if ($memoryId !== null) {
    $rule = LearnedMemory::find($memoryId);
    set_flash('success', '🧠 Lesson learned immediately — new rule (' . $rule['rule_type'] . ', '
        . $rule['context_tag'] . '): “' . $rule['learned_rule'] . '”. The next message already uses it.');
} elseif (!isset($e)) {
    set_flash('warning', 'Flag recorded. The model could not distill a rule from it — it stays queued for the batch learning job.');
}

header('Location: /admin/chat_history' . ($leadId ? "?lead_id=$leadId" : ''));
exit;
