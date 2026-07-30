<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

/**
 * The performance report as a CSV download — the same numbers Admin → Reports
 * shows on screen, so an owner can hand the month to an accountant, a landlord
 * or a judge without retyping anything off a dashboard.
 */

use App\Content\EngagementReport;
use App\Core\Auth;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

$report = EngagementReport::build(
    (int) ($_GET['days'] ?? EngagementReport::DEFAULT_DAYS),
    in_array($_GET['platform'] ?? '', CONTENT_PLATFORMS, true) ? $_GET['platform'] : null
);

$filename = sprintf(
    'belive-content-report_%s_%s-to-%s.csv',
    $report['platform'] ?? 'all-channels',
    $report['from'],
    $report['to']
);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

// UTF-8 BOM: without it Excel on Windows renders the emoji-free but accented
// room names as mojibake, and this file is opened in Excel far more than not.
echo "\xEF\xBB\xBF" . EngagementReport::csv($report);
