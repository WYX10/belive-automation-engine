<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Core\Database;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();
Auth::requireCsrf();

$id = (int) ($_POST['id'] ?? 0);

$row = Database::run('SELECT * FROM ai_custom_models WHERE id = ?', [$id])->fetch();
if (!$row) {
    set_flash('danger', 'Custom model not found (built-in models cannot be removed).');
    header('Location: /admin/models');
    exit;
}

$inUse = Database::run(
    'SELECT phase FROM ai_model_config WHERE model_key = ?',
    [$row['model_key']]
)->fetchAll(PDO::FETCH_COLUMN);
if ($inUse !== []) {
    set_flash('danger', "'{$row['model_key']}' is live for: " . implode(', ', $inUse) . ' — assign another model to that phase first.');
    header('Location: /admin/models');
    exit;
}

Database::run('DELETE FROM ai_custom_models WHERE id = ?', [$id]);
Database::run(
    'INSERT INTO ai_activity_log (action, model_used, detail) VALUES (?, ?, ?)',
    ['model_removed', $row['model_key'], "Admin removed custom model '{$row['model_key']}' from the registry"]
);

set_flash('success', "'{$row['model_key']}' removed from the registry.");
header('Location: /admin/models');
exit;
