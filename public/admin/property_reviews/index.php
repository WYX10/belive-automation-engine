<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Properties\PropertyReviewManager;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    try {
        $property = PropertyReviewManager::review(
            (int) ($_POST['property_id'] ?? 0),
            (string) ($_POST['decision'] ?? ''),
            (string) ($_SESSION['admin_username'] ?? 'admin'),
            (string) ($_POST['review_note'] ?? ''),
            (int) ($_POST['review_version'] ?? 0)
        );
        set_flash(
            'success',
            $property['name'] . ' was ' . ($property['review_status'] === 'approved'
                ? 'approved. The owner can now add rooms.'
                : 'rejected.')
        );
    } catch (\InvalidArgumentException|\RuntimeException $e) {
        set_flash('danger', $e->getMessage());
    } catch (\Throwable $e) {
        error_log('[property review] ' . $e->getMessage());
        set_flash('danger', 'The property review could not be saved. Please try again.');
    }

    $returnStatus = (string) ($_POST['return_status'] ?? 'pending');
    if (!in_array($returnStatus, ['pending', 'approved', 'rejected', 'all'], true)) {
        $returnStatus = 'pending';
    }
    header('Location: /admin/property_reviews?status=' . rawurlencode($returnStatus));
    exit;
}

$filter = (string) ($_GET['status'] ?? 'pending');
if (!in_array($filter, ['pending', 'approved', 'rejected', 'all'], true)) {
    $filter = 'pending';
}
$properties = PropertyReviewManager::forReview($filter);
$counts = PropertyReviewManager::counts();
$tone = static fn (string $status): string => match ($status) {
    'approved' => '',
    'pending' => 'orange',
    'rejected' => 'danger',
    default => 'muted',
};

admin_header('Property reviews', 'property_reviews');
?>
<div class="belive-page-head">
    <div>
        <h1>Property reviews</h1>
        <p class="belive-muted property-review-intro">Confirm the property before its owner can add rooms, prices or room-level referral rewards.</p>
    </div>
    <span class="belive-badge orange"><?= (int) $counts['pending'] ?> awaiting review</span>
</div>

<nav class="review-filter-bar" aria-label="Property review filters">
    <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $key => $label): ?>
        <a class="belive-badge <?= $filter === $key ? ($key === 'rejected' ? 'danger' : ($key === 'pending' ? 'orange' : '')) : 'muted' ?>"
           href="/admin/property_reviews?status=<?= e($key) ?>"
           <?= $filter === $key ? 'aria-current="page"' : '' ?>>
            <?= e($label) ?> (<?= (int) $counts[$key] ?>)
        </a>
    <?php endforeach; ?>
</nav>

<?php if ($properties === []): ?>
    <div class="belive-card review-empty-state">
        <h2>No <?= e($filter) ?> properties</h2>
        <p class="belive-muted">New properties submitted by owners will appear here automatically.</p>
    </div>
<?php else: ?>
    <div class="property-review-list">
        <?php foreach ($properties as $property): ?>
            <article class="belive-card property-review-card" aria-labelledby="property-review-<?= (int) $property['id'] ?>">
                <header class="property-review-head">
                    <div>
                        <div class="property-review-meta">Submitted <?= e(date('j M Y, g:ia', strtotime($property['created_at']))) ?></div>
                        <h2 id="property-review-<?= (int) $property['id'] ?>"><?= e($property['name']) ?></h2>
                        <p><?= e($property['location']) ?> &middot; <?= e($property['address']) ?></p>
                    </div>
                    <span class="belive-badge <?= $tone($property['review_status']) ?>"><?= e(ucfirst($property['review_status'])) ?></span>
                </header>

                <dl class="property-review-facts">
                    <div><dt>Owner</dt><dd><?= e($property['owner_name']) ?></dd></div>
                    <div><dt>Rooms</dt><dd><?= (int) $property['room_count'] ?></dd></div>
                    <div><dt>Description</dt><dd><?= e($property['description'] ?: 'Not provided') ?></dd></div>
                </dl>

                <?php if ($property['reviewed_at'] !== null): ?>
                    <p class="property-review-audit">Last reviewed by <?= e($property['reviewed_by'] ?: 'admin') ?> on <?= e(date('j M Y, g:ia', strtotime($property['reviewed_at']))) ?>.</p>
                <?php endif; ?>
                <?php if ($property['review_note'] !== null): ?>
                    <div class="review-existing-note"><strong>Review note</strong><p><?= e($property['review_note']) ?></p></div>
                <?php endif; ?>

                <?php if ($property['review_status'] !== 'approved'): ?>
                    <div class="property-review-actions">
                        <form method="post" action="/admin/property_reviews" class="review-action-form">
                            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                            <input type="hidden" name="property_id" value="<?= (int) $property['id'] ?>">
                            <input type="hidden" name="review_version" value="<?= (int) $property['review_version'] ?>">
                            <input type="hidden" name="decision" value="approved">
                            <input type="hidden" name="return_status" value="<?= e($filter) ?>">
                            <label for="approve-note-<?= (int) $property['id'] ?>">Approval note <span class="belive-muted">(optional)</span></label>
                            <textarea id="approve-note-<?= (int) $property['id'] ?>" name="review_note" maxlength="500" rows="3" placeholder="What did you confirm?"></textarea>
                            <button type="submit" class="belive-btn-secondary">Approve property</button>
                        </form>

                        <?php if ($property['review_status'] === 'pending'): ?>
                            <form method="post" action="/admin/property_reviews" class="review-action-form reject" data-confirm="Reject this property? The owner will see your reason and cannot add rooms.">
                                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                                <input type="hidden" name="property_id" value="<?= (int) $property['id'] ?>">
                                <input type="hidden" name="review_version" value="<?= (int) $property['review_version'] ?>">
                                <input type="hidden" name="decision" value="rejected">
                                <input type="hidden" name="return_status" value="<?= e($filter) ?>">
                                <label for="reject-note-<?= (int) $property['id'] ?>">Rejection reason</label>
                                <textarea id="reject-note-<?= (int) $property['id'] ?>" name="review_note" maxlength="500" rows="3" required placeholder="Explain what the owner must correct"></textarea>
                                <button type="submit" class="belive-btn-danger">Reject property</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php elseif ((int) $property['room_count'] === 0): ?>
                    <p class="belive-alert success property-review-ready">Approved. The owner can now add the first room.</p>
                <?php else: ?>
                    <p class="belive-alert success property-review-ready">Approved and in use by <?= (int) $property['room_count'] ?> room<?= (int) $property['room_count'] === 1 ? '' : 's' ?>.</p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php admin_footer();
