<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Core\Database;
use App\Models\ApiCredential;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();
Auth::requireCsrf();

$id = (int) ($_POST['id'] ?? 0);

$row = ApiCredential::find($id);
if ($row === null) {
    set_flash('danger', 'Credential not found.');
    header('Location: /admin/credentials');
    exit;
}

// The active key is what Eve is running on right now — deactivate it (by
// activating a replacement) before it can be deleted.
if ((int) $row['is_active'] === 1) {
    set_flash('danger', 'This credential is active — activate another key for this service first, then delete.');
    header('Location: /admin/credentials');
    exit;
}

ApiCredential::delete($id);

Database::run(
    'INSERT INTO ai_activity_log (action, detail) VALUES (?, ?)',
    ['credential_deleted', "{$row['service']} credential \"{$row['label']}\" (#$id) deleted by admin"]
);

set_flash('success', "Credential \"{$row['label']}\" deleted.");
header('Location: /admin/credentials');
exit;
