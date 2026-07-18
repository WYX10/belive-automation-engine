<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Core\Database;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();
Auth::requireCsrf();

$phase = $_POST['phase'] ?? '';
$modelKey = $_POST['model_key'] ?? '';

$registry = App\AI\ModelRouter::registry();

if (!in_array($phase, AI_PHASES, true) || !isset($registry[$modelKey])) {
    set_flash('danger', 'Unknown phase or model.');
} else {
    Database::run(
        'INSERT INTO ai_model_config (phase, model_key) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE model_key = VALUES(model_key)',
        [$phase, $modelKey]
    );
    Database::run(
        'INSERT INTO ai_activity_log (action, phase, model_used, detail) VALUES (?, ?, ?, ?)',
        ['model_switched', $phase, $modelKey, "Admin set $phase to $modelKey"]
    );
    set_flash('success', "{$registry[$modelKey]['label']} is now live for " . str_replace('_', ' ', $phase) . '.');
}

header('Location: /admin/models');
exit;
