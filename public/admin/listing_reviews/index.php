<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Core\Database;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

$filter = $_GET['status'] ?? 'pending';
if (!in_array($filter, ['pending', 'approved', 'rejected', 'all'], true)) {
    $filter = 'pending';
}

$evidenceWhere = '(vl.ownership_doc_path IS NOT NULL OR vl.gps_lat IS NOT NULL OR vl.scam_checked_at IS NOT NULL)';
$where = match ($filter) {
    'pending' => "($evidenceWhere AND (vl.ownership_review_status = 'pending' OR vl.gps_review_status = 'pending'))",
    'approved' => "($evidenceWhere AND vl.verified_badge = 1)",
    'rejected' => "($evidenceWhere AND (vl.ownership_review_status = 'rejected' OR vl.gps_review_status = 'rejected'))",
    default => $evidenceWhere,
};

$reviews = Database::run(
    "SELECT vl.*, r.name AS room_name, r.property_name, r.room_code, r.location, r.room_type,
            r.owner_name, r.address
     FROM verified_listings vl
     JOIN rooms r ON r.id = vl.room_id
     WHERE $where
     ORDER BY
        CASE WHEN vl.ownership_review_status = 'pending' OR vl.gps_review_status = 'pending' THEN 0 ELSE 1 END,
        vl.updated_at DESC"
)->fetchAll();

$counts = Database::run(
    "SELECT
        SUM(CASE WHEN $evidenceWhere THEN 1 ELSE 0 END) AS total,
        SUM(CASE WHEN $evidenceWhere AND (vl.ownership_review_status = 'pending' OR vl.gps_review_status = 'pending') THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN $evidenceWhere AND vl.verified_badge = 1 THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN $evidenceWhere AND (vl.ownership_review_status = 'rejected' OR vl.gps_review_status = 'rejected') THEN 1 ELSE 0 END) AS rejected
     FROM verified_listings vl"
)->fetch() ?: [];

$tone = static fn (string $status): string => match ($status) {
    'approved' => '',
    'pending' => 'orange',
    'rejected' => 'danger',
    default => 'muted',
};

admin_header('Listing reviews', 'listing_reviews');
?>
<div class="belive-page-head">
    <div>
        <h1>Listing reviews</h1>
        <p class="belive-muted" style="font-size:13.5px; margin-top:4px">Review ownership evidence and location matches before a tenant sees the verified badge.</p>
    </div>
    <span class="belive-badge orange"><?= (int) ($counts['pending'] ?? 0) ?> awaiting review</span>
</div>

<nav class="review-filter-bar" aria-label="Listing review filters">
    <?php foreach (['pending' => 'Pending', 'approved' => 'Verified', 'rejected' => 'Rejected', 'all' => 'All'] as $key => $label): ?>
        <a class="belive-badge <?= $filter === $key ? ($key === 'rejected' ? 'danger' : ($key === 'pending' ? 'orange' : '')) : 'muted' ?>"
           href="/admin/listing_reviews?status=<?= e($key) ?>"
           <?= $filter === $key ? 'aria-current="page"' : '' ?>>
            <?= e($label) ?> (<?= (int) ($counts[$key === 'all' ? 'total' : $key] ?? 0) ?>)
        </a>
    <?php endforeach; ?>
</nav>

<div class="belive-card review-table-wrap">
    <?php if ($reviews === []): ?>
        <div class="review-empty-state">
            <h2>No <?= e($filter) ?> reviews</h2>
            <p class="belive-muted">New owner submissions will appear here automatically.</p>
        </div>
    <?php else: ?>
        <div class="belive-table-wrap">
            <table class="belive-table review-table">
                <thead>
                <tr><th>Listing</th><th>Owner</th><th>Ownership</th><th>Location</th><th>AI screen</th><th>Badge</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($reviews as $review): ?>
                    <?php $flags = json_decode($review['scam_flags'] ?? '[]', true) ?: []; ?>
                    <tr>
                        <td>
                            <strong><?= e($review['property_name'] ?: $review['room_name']) ?></strong>
                            <div class="belive-muted" style="font-size:12px"><?= e($review['room_code'] ?: 'Room #' . $review['room_id']) ?> · <?= e($review['location']) ?></div>
                        </td>
                        <td><?= e($review['owner_name'] ?: 'Unassigned') ?></td>
                        <td><span class="belive-badge <?= $tone($review['ownership_review_status']) ?>"><?= e(str_replace('_', ' ', $review['ownership_review_status'])) ?></span></td>
                        <td><span class="belive-badge <?= $tone($review['gps_review_status']) ?>"><?= e(str_replace('_', ' ', $review['gps_review_status'])) ?></span></td>
                        <td>
                            <?php if ($review['scam_checked_at'] === null): ?>
                                <span class="belive-badge muted">not screened</span>
                            <?php elseif ($flags === []): ?>
                                <span class="belive-badge">clean</span>
                            <?php else: ?>
                                <span class="belive-badge <?= count(array_filter($flags, fn ($flag) => ($flag['severity'] ?? '') === 'high')) > 0 ? 'danger' : 'orange' ?>"><?= count($flags) ?> flag(s)</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="belive-badge <?= (int) $review['verified_badge'] === 1 ? '' : 'muted' ?>"><?= (int) $review['verified_badge'] === 1 ? 'verified' : 'not verified' ?></span></td>
                        <td><a class="belive-btn-ghost review-open-button" href="/admin/listing_reviews/view?id=<?= (int) $review['id'] ?>">Open review</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php admin_footer();
