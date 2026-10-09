<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\Lead;
use App\Models\Interaction;

if (!Database::isPostgres()) {
    return;
}

$literal = "CURDATE() INSERT IGNORE INTERVAL 5 MINUTE `tenant`";
check('PostgreSQL conversion never rewrites quoted message text', Database::run("SELECT '$literal' AS message")->fetchColumn() === $literal);
$schemaName = $cfg['schema'];
check('PostgreSQL uses the private application schema', Database::run('SELECT current_schema()')->fetchColumn() === $schemaName);

$ciLead = Lead::create(['wa_phone' => 'PG-CASE-ONE', 'source_channel' => 'website']);
$ciDuplicateRejected = false;
try {
    Lead::create(['wa_phone' => 'pg-case-one', 'source_channel' => 'website']);
} catch (PDOException $e) {
    $ciDuplicateRejected = $e->getCode() === '23505';
}
check('case-insensitive MySQL unique keys remain case-insensitive in PostgreSQL', $ciDuplicateRejected);
check('room and tenant searches remain case-insensitive', (int) Database::run('SELECT COUNT(*) FROM leads WHERE wa_phone LIKE ?', ['pg-case-%'])->fetchColumn() === 1);

Database::run("UPDATE leads SET updated_at = '2000-01-01 00:00:00' WHERE id = ?", [$ciLead]);
Lead::update($ciLead, ['wa_phone' => 'PG-CASE-ONE']);
check('no-op updates preserve the imported audit timestamp', substr(Lead::find($ciLead)['updated_at'], 0, 4) === '2000');
Lead::update($ciLead, ['name' => 'PostgreSQL fixture']);
check('PostgreSQL updates the automatic audit timestamp', substr(Lead::find($ciLead)['updated_at'], 0, 4) !== '2000');
$jsonId = Interaction::create(['lead_id' => $ciLead, 'phase' => 'conversion', 'skill' => 'create', 'model_used' => 'postgres-fixture', 'direction' => 'outbound', 'memory_used' => '[7,9]']);
check('learned-rule references can be searched in PostgreSQL JSON', (int) Database::run("SELECT COUNT(*) FROM ai_interactions WHERE id = ? AND JSON_CONTAINS(COALESCE(memory_used, '[]'), ?)", [$jsonId, '7'])->fetchColumn() === 1);

$badConfig = $cfg;
$badConfig['host'] = 'host;sslmode=disable';
$injectionRejected = false;
try { Database::connect($badConfig); } catch (InvalidArgumentException) { $injectionRejected = true; }
check('connection settings cannot inject additional DSN options', $injectionRejected);
$badConfig = $cfg;
$badConfig['host'] = 'example.com';
$badConfig['sslmode'] = 'disable';
$unencryptedRejected = false;
try { Database::connect($badConfig); } catch (InvalidArgumentException) { $unencryptedRejected = true; }
check('hosted PostgreSQL cannot disable TLS', $unencryptedRejected);

Lead::delete($ciLead);
