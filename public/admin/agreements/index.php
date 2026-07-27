<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Agreements\DigitalAgreementGenerator;
use App\Core\Auth;
use App\Core\Database;
use App\Models\DigitalAgreement;

require dirname(__DIR__) . '/_layout.php';
Auth::requireAdmin();

$adminName = (string) ($_SESSION['admin_username'] ?? 'admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'generate') {
    Auth::requireCsrf();
    $leadId = (int) ($_POST['lead_id'] ?? 0);
    $roomId = (int) ($_POST['room_id'] ?? 0);
    $startsOn = trim($_POST['starts_on'] ?? '');

    // Only ever draft against a room the tenant actually confirmed.
    $confirmed = Database::run(
        "SELECT COUNT(*) FROM bookings
         WHERE lead_id = ? AND room_id = ? AND status IN ('confirmed', 'completed')",
        [$leadId, $roomId]
    )->fetchColumn() > 0;

    try {
        if (!$confirmed) {
            throw new RuntimeException('That tenant has no confirmed booking for that room.');
        }
        $agreement = DigitalAgreementGenerator::generate($leadId, $roomId, $startsOn, $adminName);
        set_flash('success', 'Draft written by ' . $agreement['generated_by_model'] . '. Review it, then send it to the owner.');
        header('Location: /admin/agreements/view?id=' . (int) $agreement['id']);
        exit;
    } catch (Throwable $e) {
        set_flash('danger', 'Could not draft the agreement: ' . $e->getMessage());
    }

    header('Location: /admin/agreements');
    exit;
}

$stageFilter = $_GET['stage'] ?? 'open';
$counts = DigitalAgreement::stageCounts();
$awaiting = DigitalAgreement::awaitingGeneration();

$where = match ($stageFilter) {
    'all' => '',
    'open' => "WHERE a.status IN ('draft', 'owner_review', 'admin_review', 'tenant_review')",
    default => in_array($stageFilter, [...DigitalAgreement::STAGES, 'cancelled'], true)
        ? 'WHERE a.status = ' . Database::pdo()->quote($stageFilter)
        : '',
};

$agreements = Database::run(
    "SELECT a.*, l.name AS tenant_name, l.wa_phone, r.name AS room_name, r.property_name
     FROM digital_agreements a
     JOIN leads l ON l.id = a.lead_id
     LEFT JOIN rooms r ON r.id = a.room_id
     $where
     ORDER BY FIELD(a.status, 'admin_review', 'draft', 'owner_review', 'tenant_review', 'completed', 'cancelled'), a.id DESC
     LIMIT 200"
)->fetchAll();

$tone = static fn (string $status): string => match ($status) {
    'completed' => '',
    'admin_review', 'draft' => 'orange',
    'cancelled' => 'danger',
    default => 'muted',
};

$filters = [
    'open'          => 'In progress',
    'draft'         => 'Drafts',
    'owner_review'  => 'With owner',
    'admin_review'  => 'Needs my review',
    'tenant_review' => 'With tenant',
    'completed'     => 'Signed',
    'cancelled'     => 'Cancelled',
    'all'           => 'All',
];

admin_header('Agreements', 'agreements');
?>
<div class="belive-page-head">
    <div>
        <h1>Agreements</h1>
        <p class="belive-muted" style="font-size:13.5px; margin-top:4px">
            Tenant confirms the room → AI drafts the agreement → the owner adds their details and signs →
            you check it → the tenant signs.
        </p>
    </div>
    <span class="belive-badge <?= $counts['admin_review'] > 0 ? 'orange' : 'muted' ?>">
        <?= (int) $counts['admin_review'] ?> waiting on you
    </span>
</div>

<div class="belive-card agreement-start-card">
    <div class="belive-card-title">✨ Confirmed rentals waiting for an agreement</div>
    <?php if ($awaiting === []): ?>
        <p class="belive-muted">Nothing waiting. As soon as a tenant confirms a room, they appear here and
        Eve can draft their agreement in one click.</p>
    <?php else: ?>
        <div class="review-table-wrap">
            <table class="belive-table review-table">
                <thead><tr><th>Tenant</th><th>Room</th><th>Owner</th><th>Move-in</th><th>Draft the agreement</th></tr></thead>
                <tbody>
                <?php foreach ($awaiting as $row): ?>
                    <?php
                    $moveIn = strtotime((string) $row['move_in_date']);
                    $defaultStart = $moveIn !== false && $moveIn > strtotime('-1 year')
                        ? date('Y-m-d', $moveIn)
                        : date('Y-m-d');
                    ?>
                    <tr>
                        <td>
                            <a href="/admin/leads/view?id=<?= (int) $row['lead_id'] ?>"><?= e($row['tenant_name'] ?: $row['wa_phone']) ?></a>
                            <div class="belive-muted" style="font-size:12px"><?= e($row['wa_phone']) ?></div>
                        </td>
                        <td style="font-size:13.5px"><?= e($row['room_name']) ?><div class="belive-muted" style="font-size:12px"><?= e($row['location']) ?></div></td>
                        <td style="font-size:13px"><?= e($row['owner_name'] ?: '— no owner on the room —') ?></td>
                        <td style="font-size:13px"><?= e($row['move_in_date'] ?: '—') ?></td>
                        <td>
                            <form method="post" action="/admin/agreements" class="agreement-start-form">
                                <input type="hidden" name="do" value="generate">
                                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                                <input type="hidden" name="lead_id" value="<?= (int) $row['lead_id'] ?>">
                                <input type="hidden" name="room_id" value="<?= (int) $row['room_id'] ?>">
                                <label class="belive-muted" for="start-<?= (int) $row['lead_id'] ?>-<?= (int) $row['room_id'] ?>">Starts</label>
                                <input id="start-<?= (int) $row['lead_id'] ?>-<?= (int) $row['room_id'] ?>"
                                       name="starts_on" type="date" value="<?= e($defaultStart) ?>" required>
                                <button type="submit" class="belive-btn-primary review-open-button">Draft with AI</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="belive-muted" style="font-size:12px; margin-top:10px">
            The tenure comes from what the tenant told Eve; the rent is taken from the room's price for that
            tenure and frozen onto the agreement.
        </p>
    <?php endif; ?>
</div>

<div class="review-filter-bar" style="margin-top:18px">
    <?php foreach ($filters as $key => $label): ?>
        <?php $count = $key === 'open'
            ? $counts['draft'] + $counts['owner_review'] + $counts['admin_review'] + $counts['tenant_review']
            : ($key === 'all' ? array_sum($counts) : $counts[$key] ?? 0); ?>
        <a class="<?= $stageFilter === $key ? 'belive-btn-secondary' : 'belive-btn-ghost' ?>"
           href="/admin/agreements?stage=<?= e($key) ?>"><?= e($label) ?> (<?= (int) $count ?>)</a>
    <?php endforeach; ?>
</div>

<div class="belive-card">
    <?php if ($agreements === []): ?>
        <div class="review-empty-state">
            <h2>Nothing here</h2>
            <p class="belive-muted">No agreement is at this stage right now.</p>
        </div>
    <?php else: ?>
        <div class="review-table-wrap">
            <table class="belive-table review-table">
                <thead><tr><th>#</th><th>Tenant</th><th>Room</th><th>Owner</th><th>Term</th><th>Stage</th><th>Sitting with</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($agreements as $agreement): ?>
                    <?php $stage = DigitalAgreement::stage($agreement['status']); ?>
                    <tr>
                        <td>BL-AGR-<?= sprintf('%05d', (int) $agreement['id']) ?></td>
                        <td><?= e($agreement['tenant_name'] ?: $agreement['wa_phone']) ?></td>
                        <td style="font-size:13px"><?= e($agreement['room_name'] ?? '—') ?></td>
                        <td style="font-size:13px"><?= e($agreement['owner_name'] ?: '—') ?></td>
                        <td style="font-size:13px; white-space:nowrap">
                            <?php if ($agreement['starts_on'] !== null && $agreement['ends_on'] !== null): ?>
                                <?= e(date('j M Y', strtotime($agreement['starts_on']))) ?><br>
                                <span class="belive-muted">to <?= e(date('j M Y', strtotime($agreement['ends_on']))) ?></span>
                            <?php else: ?>
                                <span class="belive-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="belive-badge <?= $tone($agreement['status']) ?>"><?= e($stage['label']) ?></span></td>
                        <td style="font-size:13px"><?= e(ucfirst($stage['holder'])) ?></td>
                        <td><a class="belive-btn-ghost review-open-button" href="/admin/agreements/view?id=<?= (int) $agreement['id'] ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php admin_footer();
