<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Core\Auth;
use App\Core\Database;
use App\Verification\ListingVerifier;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$loadReview = static function (int $reviewId): ?array {
    $row = Database::run(
        'SELECT vl.*, r.name AS room_name, r.property_name, r.room_code, r.location, r.room_type,
                r.owner_name, r.address
         FROM verified_listings vl
         JOIN rooms r ON r.id = vl.room_id
         WHERE vl.id = ? LIMIT 1',
        [$reviewId]
    )->fetch();
    return $row ?: null;
};

$review = $loadReview($id);
if ($review === null) {
    set_flash('danger', 'Listing review not found.');
    header('Location: /admin/listing_reviews');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireCsrf();
    $section = $_POST['section'] ?? '';
    $decision = $_POST['decision'] ?? '';
    $note = trim($_POST['review_note'] ?? '');
    $reviewer = (string) ($_SESSION['admin_username'] ?? 'admin');
    $evidenceVersion = filter_var($_POST['evidence_version'] ?? null, FILTER_VALIDATE_INT);

    try {
        if ($evidenceVersion === false) {
            throw new RuntimeException('Evidence version is missing. Reload the review page.');
        }
        if ($section === 'ownership') {
            ListingVerifier::reviewOwnership((int) $review['room_id'], $decision, $reviewer, $note, (int) $evidenceVersion);
        } elseif ($section === 'gps') {
            ListingVerifier::reviewGps((int) $review['room_id'], $decision, $reviewer, $note, (int) $evidenceVersion);
        } else {
            throw new RuntimeException('Unknown review section.');
        }
        set_flash('success', ucfirst($section) . " review recorded as $decision.");
    } catch (Throwable $e) {
        set_flash('danger', $e->getMessage());
    }

    header('Location: /admin/listing_reviews/view?id=' . $id);
    exit;
}

$flags = json_decode($review['scam_flags'] ?? '[]', true) ?: [];
$highFlags = array_filter($flags, fn ($flag) => ($flag['severity'] ?? '') === 'high');
$tone = static fn (string $status): string => match ($status) {
    'approved' => '',
    'pending' => 'orange',
    'rejected' => 'danger',
    default => 'muted',
};

admin_header('Review ' . ($review['property_name'] ?: $review['room_name']), 'listing_reviews');
?>
<div class="belive-page-head">
    <div>
        <a href="/admin/listing_reviews" class="review-back-link">← Listing reviews</a>
        <h1 style="margin-top:6px"><?= e($review['property_name'] ?: $review['room_name']) ?></h1>
        <p class="belive-muted" style="font-size:13.5px; margin-top:4px"><?= e($review['room_code'] ?: 'Room #' . $review['room_id']) ?> · <?= e($review['location']) ?> · <?= e($review['room_type']) ?> room</p>
    </div>
    <span class="belive-badge <?= (int) $review['verified_badge'] === 1 ? '' : 'muted' ?>"><?= (int) $review['verified_badge'] === 1 ? '✓ Verified badge active' : 'Badge not active' ?></span>
</div>

<div class="belive-card review-listing-summary">
    <div><span class="belive-muted">Owner</span><strong><?= e($review['owner_name'] ?: 'Unassigned') ?></strong></div>
    <div><span class="belive-muted">Listed address</span><strong><?= e($review['address'] ?: 'No address supplied') ?></strong></div>
    <div><span class="belive-muted">Last updated</span><strong><?= e($review['updated_at']) ?></strong></div>
</div>

<div class="review-section-grid">
    <section class="belive-card review-section" aria-labelledby="ownership-heading">
        <div class="review-section-head">
            <div>
                <h2 id="ownership-heading">Ownership evidence</h2>
                <p class="belive-muted">Confirm that the document identifies the owner and matches this property.</p>
            </div>
            <span class="belive-badge <?= $tone($review['ownership_review_status']) ?>"><?= e(str_replace('_', ' ', $review['ownership_review_status'])) ?></span>
        </div>

        <?php if (empty($review['ownership_doc_path'])): ?>
            <div class="belive-alert warning">The owner has not submitted an ownership document.</div>
        <?php else: ?>
            <div class="review-evidence-row">
                <div><span class="belive-muted">Submitted evidence</span><strong>Title deed, SPA or utility bill</strong></div>
                <a class="belive-btn-ghost" href="<?= e($review['ownership_doc_path']) ?>" target="_blank" rel="noopener noreferrer">Open document ↗</a>
            </div>
            <?php if ($review['ownership_reviewed_at'] !== null): ?>
                <p class="review-audit-line">Reviewed by <?= e($review['ownership_reviewed_by']) ?> on <?= e($review['ownership_reviewed_at']) ?></p>
            <?php endif; ?>
            <?php if ($review['ownership_review_note'] !== null): ?>
                <div class="review-existing-note"><strong>Review note</strong><p><?= e($review['ownership_review_note']) ?></p></div>
            <?php endif; ?>

            <div class="review-decision-forms">
                <?php if ($review['ownership_review_status'] !== 'approved'): ?>
                    <form method="post" class="review-action-form">
                        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="section" value="ownership">
                        <input type="hidden" name="decision" value="approved">
                        <input type="hidden" name="evidence_version" value="<?= (int) $review['ownership_evidence_version'] ?>">
                        <label for="ownership-approve-note">Approval note <span class="belive-muted">(optional)</span></label>
                        <textarea id="ownership-approve-note" name="review_note" maxlength="500" rows="3" placeholder="What did you verify?"></textarea>
                        <button type="submit" class="belive-btn-secondary">Approve ownership</button>
                    </form>
                <?php endif; ?>
                <?php if ($review['ownership_review_status'] !== 'rejected'): ?>
                    <form method="post" class="review-action-form reject" data-confirm="Reject this ownership evidence? The owner will see your reason and can resubmit.">
                        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="section" value="ownership">
                        <input type="hidden" name="decision" value="rejected">
                        <input type="hidden" name="evidence_version" value="<?= (int) $review['ownership_evidence_version'] ?>">
                        <label for="ownership-reject-note">Rejection reason</label>
                        <textarea id="ownership-reject-note" name="review_note" maxlength="500" rows="3" required placeholder="Explain what the owner must correct"></textarea>
                        <button type="submit" class="belive-btn-danger">Reject ownership</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="belive-card review-section" aria-labelledby="gps-heading">
        <div class="review-section-head">
            <div>
                <h2 id="gps-heading">Location match</h2>
                <p class="belive-muted">Compare the submitted coordinates with the listed address.</p>
            </div>
            <span class="belive-badge <?= $tone($review['gps_review_status']) ?>"><?= e(str_replace('_', ' ', $review['gps_review_status'])) ?></span>
        </div>

        <?php if ($review['gps_lat'] === null || $review['gps_lng'] === null): ?>
            <div class="belive-alert warning">The owner has not submitted location coordinates.</div>
        <?php else: ?>
            <div class="review-evidence-row">
                <div><span class="belive-muted">Coordinates</span><strong><?= e($review['gps_lat']) ?>, <?= e($review['gps_lng']) ?></strong></div>
                <a class="belive-btn-ghost" href="https://www.openstreetmap.org/?mlat=<?= e($review['gps_lat']) ?>&mlon=<?= e($review['gps_lng']) ?>#map=17/<?= e($review['gps_lat']) ?>/<?= e($review['gps_lng']) ?>" target="_blank" rel="noopener noreferrer">Open map ↗</a>
            </div>
            <?php if ($review['gps_reviewed_at'] !== null): ?>
                <p class="review-audit-line">Reviewed by <?= e($review['gps_reviewed_by']) ?> on <?= e($review['gps_reviewed_at']) ?></p>
            <?php endif; ?>
            <?php if ($review['gps_review_note'] !== null): ?>
                <div class="review-existing-note"><strong>Review note</strong><p><?= e($review['gps_review_note']) ?></p></div>
            <?php endif; ?>

            <div class="review-decision-forms">
                <?php if ($review['gps_review_status'] !== 'approved'): ?>
                    <form method="post" class="review-action-form">
                        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="section" value="gps">
                        <input type="hidden" name="decision" value="approved">
                        <input type="hidden" name="evidence_version" value="<?= (int) $review['gps_evidence_version'] ?>">
                        <label for="gps-approve-note">Approval note <span class="belive-muted">(optional)</span></label>
                        <textarea id="gps-approve-note" name="review_note" maxlength="500" rows="3" placeholder="What matched the address?"></textarea>
                        <button type="submit" class="belive-btn-secondary">Approve location</button>
                    </form>
                <?php endif; ?>
                <?php if ($review['gps_review_status'] !== 'rejected'): ?>
                    <form method="post" class="review-action-form reject" data-confirm="Reject this location match? The owner will see your reason and can resubmit.">
                        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="section" value="gps">
                        <input type="hidden" name="decision" value="rejected">
                        <input type="hidden" name="evidence_version" value="<?= (int) $review['gps_evidence_version'] ?>">
                        <label for="gps-reject-note">Rejection reason</label>
                        <textarea id="gps-reject-note" name="review_note" maxlength="500" rows="3" required placeholder="Explain what the owner must correct"></textarea>
                        <button type="submit" class="belive-btn-danger">Reject location</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<section class="belive-card review-screening-card" aria-labelledby="screening-heading">
    <div class="review-section-head">
        <div><h2 id="screening-heading">AI scam-pattern screen</h2><p class="belive-muted">A screening aid for admin judgement, not a fraud verdict.</p></div>
        <?php if ($review['scam_checked_at'] === null): ?>
            <span class="belive-badge muted">not screened</span>
        <?php elseif ($flags === []): ?>
            <span class="belive-badge">clean</span>
        <?php else: ?>
            <span class="belive-badge <?= $highFlags !== [] ? 'danger' : 'orange' ?>"><?= count($flags) ?> flag(s)</span>
        <?php endif; ?>
    </div>
    <?php if ($review['scam_checked_at'] === null): ?>
        <p class="belive-muted">The owner has not run the AI screen yet.</p>
    <?php elseif ($flags === []): ?>
        <p>No common scam patterns were flagged when screened on <?= e($review['scam_checked_at']) ?>.</p>
    <?php else: ?>
        <div class="review-flag-list">
            <?php foreach ($flags as $flag): ?>
                <div class="belive-alert <?= ($flag['severity'] ?? '') === 'high' ? 'danger' : 'warning' ?>">
                    <strong><?= e($flag['pattern'] ?? 'Pattern flagged') ?></strong> (<?= e($flag['severity'] ?? 'unknown') ?>)
                    <div><?= e($flag['detail'] ?? '') ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($highFlags !== []): ?><p class="review-badge-blocked">The verified badge is blocked until high-severity flags are resolved.</p><?php endif; ?>
</section>
<?php admin_footer();
