<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Catalog\RoomRepository;
use App\Models\DigitalAgreement;
use App\Models\Room;

require dirname(__DIR__) . '/_portal_layout.php';
$lead = require_tenant();

$tenancy = tenant_tenancy($lead);
$room = $tenancy['room'];
$agreement = $tenancy['agreement'];
$timeline = $tenancy['timeline'];
$tenure = tenant_tenure($lead, $agreement);

// What they pay is the figure snapshotted on the agreement they signed; the
// room's current listed rate is only a stand-in when there is no agreement, and
// is labelled as such rather than passed off as their rent.
$rent = $agreement !== null && $agreement['monthly_rent_rm'] !== null
    ? (float) $agreement['monthly_rent_rm']
    : null;

$similar = $room !== null
    ? RoomRepository::similarInArea($room, $tenure, $rent)
    : [];

portal_header('tenant', 'My room', 'my_room');
?>
<div class="portal-hero">
    <div class="tagline">The Smarter Way to Rent.</div>
    <h1>My room</h1>
    <p>The room you rent, how long you have it for, and what else is free in your area.</p>
</div>

<?php if ($room === null): ?>
    <div class="belive-card" style="max-width:720px">
        <div class="belive-card-title">🏠 No room yet</div>
        <p class="belive-muted">You are not renting a room through BeLive yet. Once a room is booked and your
        agreement is signed, it appears here with your rental dates.</p>
        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:14px">
            <a class="belive-btn-primary" href="/rooms">Browse rooms</a>
            <a class="belive-btn-secondary" href="<?= e(eve_whatsapp_link()) ?>" target="_blank" rel="noopener">💬 Ask Eve</a>
        </div>
    </div>
<?php else: ?>
    <div class="belive-card tenant-room-card">
        <div class="tenant-room-heading">
            <div>
                <?php if ($room['room_code'] !== null && $room['room_code'] !== ''): ?>
                    <div class="tenant-room-code"><?= e($room['room_code']) ?></div>
                <?php endif; ?>
                <h2><?= e($room['name']) ?></h2>
                <p class="belive-muted tenant-room-where">
                    <?php
                    // Development · house · area — the hierarchy the portfolio is
                    // organised by, with only the levels this room actually has.
                    $where = array_values(array_filter([
                        $room['property_name'] ?? null,
                        $room['house_name'] ?? null,
                        $room['location'] ?? null,
                    ], static fn ($part): bool => is_string($part) && trim($part) !== ''));
                    ?>
                    📍 <?= e(implode(' · ', $where)) ?>
                </p>
                <p class="belive-muted" style="font-size:13px">
                    <?= e(ucfirst((string) $room['room_type'])) ?> room · RM 0 deposit
                    <?php if ($room['address'] !== null && $room['address'] !== ''): ?>
                        · <?= e($room['address']) ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="tenant-room-rent">
                <?php if ($rent !== null): ?>
                    <strong>RM <?= e(number_format($rent, 2)) ?></strong>
                    <span>a month · <?= e(Room::TENURE_LABELS[$tenure] ?? $tenure) ?></span>
                <?php elseif (isset($room['prices'][$tenure])): ?>
                    <strong>RM <?= e(number_format($room['prices'][$tenure]['price'], 2)) ?></strong>
                    <span>listed rate · <?= e(Room::TENURE_LABELS[$tenure] ?? $tenure) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($room['images'] !== []): ?>
            <div class="tenant-room-gallery">
                <?php foreach (array_slice($room['images'], 0, 6) as $image): ?>
                    <img src="<?= e($image) ?>" alt="<?= e($room['name']) ?>" loading="lazy">
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($room['amenities'] !== []): ?>
            <ul class="belive-check-list" style="margin-top:16px">
                <?php foreach ($room['amenities'] as $amenity): ?>
                    <li><?= e($amenity) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:16px">
            <a class="belive-btn-ghost" href="/rooms/<?= (int) $room['id'] ?>">See the full listing</a>
            <a class="belive-btn-ghost" href="/tenant/move_in_log">📷 Move-in condition log</a>
            <a class="belive-btn-ghost" href="/tenant/electric">⚡ My electric bill</a>
        </div>
    </div>

    <div class="belive-card" style="margin-top:16px">
        <div class="belive-card-title">📆 How long you have it for</div>
        <?php if ($timeline === null): ?>
            <p class="belive-muted">
                <?= $tenancy['source'] === 'booking'
                    ? 'Your rental dates appear here once your agreement is signed by both you and the owner. Until then this is the room you have booked, not a term that has started.'
                    : 'This agreement has no confirmed start and end date, so there is no term to count down. Ask BeLive to issue an updated one.' ?>
            </p>
            <div style="margin-top:14px">
                <a class="belive-btn-ghost" href="/tenant/agreement">📄 My agreement</a>
            </div>
        <?php else: ?>
            <?php
            $termLabel = DigitalAgreement::termLabel($timeline);
            $copy = match ($timeline['state']) {
                'upcoming' => [
                    'badge' => 'Starts soon',
                    'value' => (string) $timeline['days_until_start'],
                    'label' => $timeline['days_until_start'] === 1 ? 'day until you move in' : 'days until you move in',
                ],
                'expired' => [
                    'badge' => 'Term ended',
                    'value' => (string) $timeline['days_since_end'],
                    'label' => $timeline['days_since_end'] === 1 ? 'day since it ended' : 'days since it ended',
                ],
                'ending_today' => ['badge' => 'Last day', 'value' => '0', 'label' => 'days left'],
                'ending_soon' => [
                    'badge' => 'Ending soon',
                    'value' => (string) $timeline['days_remaining'],
                    'label' => $timeline['days_remaining'] === 1 ? 'day left' : 'days left',
                ],
                default => [
                    'badge' => 'Renting now',
                    'value' => (string) $timeline['days_remaining'],
                    'label' => $timeline['days_remaining'] === 1 ? 'day left' : 'days left',
                ],
            };
            ?>
            <?php /* CSS state classes are hyphenated; the timeline states are not. */ ?>
            <section class="agreement-timeline state-<?= e(str_replace('_', '-', $timeline['state'])) ?>"
                     style="margin-top:12px" aria-labelledby="tenancy-time-heading">
                <div class="agreement-time-summary">
                    <span class="agreement-time-badge"><?= e($copy['badge']) ?></span>
                    <div class="agreement-time-count" id="tenancy-time-heading">
                        <strong><?= e($copy['value']) ?></strong>
                        <span><?= e($copy['label']) ?></span>
                    </div>
                </div>
                <div class="agreement-date-range">
                    <div>
                        <span>Rental starts</span>
                        <time datetime="<?= e($timeline['starts_on']) ?>"><?= e(date('j M Y', strtotime($timeline['starts_on']))) ?></time>
                    </div>
                    <div>
                        <span>Rental ends</span>
                        <time datetime="<?= e($timeline['ends_on']) ?>"><?= e(date('j M Y', strtotime($timeline['ends_on']))) ?></time>
                    </div>
                </div>
                <div class="agreement-progress" role="progressbar" aria-label="Rental term elapsed"
                     aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $timeline['progress_percent'] ?>">
                    <span style="width:<?= (int) $timeline['progress_percent'] ?>%"></span>
                </div>
            </section>
            <p class="belive-muted" style="font-size:13px">
                <?= $termLabel !== null ? e($termLabel) . ' in total' : 'Full term' ?>
                <?php if ($agreement !== null && $agreement['tenure'] !== null): ?>
                    · signed as <?= e(Room::TENURE_LABELS[$agreement['tenure']] ?? $agreement['tenure']) ?>
                <?php endif; ?>
                · <?= (int) $timeline['progress_percent'] ?>% of your term has passed
            </p>
            <div style="margin-top:12px">
                <a class="belive-btn-ghost" href="/tenant/agreement">📄 Read my agreement</a>
            </div>
        <?php endif; ?>
    </div>

    <div class="belive-card" style="margin-top:16px">
        <div class="belive-card-title">🔎 More rooms in <?= e($room['location']) ?></div>
        <?php if ($similar === []): ?>
            <p class="belive-muted">Nothing else is free in <?= e($room['location']) ?> right now — these are the only
            rooms we would put in front of you, because a room in another area is not a room near you.
            <a href="<?= e(eve_whatsapp_link()) ?>" target="_blank" rel="noopener">Tell Eve</a> what you are after and
            she will message you when one opens up.</p>
        <?php else: ?>
            <p class="belive-muted" style="font-size:13.5px">
                Free right now in your own area — same neighbourhood, closest to your room first. Handy if a friend is
                looking, or if you want a different room without leaving <?= e($room['location']) ?>.
            </p>
            <div class="tenant-similar-grid">
                <?php foreach ($similar as $option): ?>
                    <a class="tenant-similar-card" href="/rooms/<?= (int) $option['id'] ?>">
                        <div class="photo">
                            <?php if ($option['cover_image'] !== null): ?>
                                <img src="<?= e($option['cover_image']) ?>" alt="<?= e($option['name']) ?>" loading="lazy">
                            <?php endif; ?>
                        </div>
                        <div class="body">
                            <?php /* The room, not the building: every card here is in the same area
                                     and often the same development, so the building name alone would
                                     make three different rooms read as one. */ ?>
                            <div class="title"><?= e($option['name']) ?></div>
                            <div class="belive-muted" style="font-size:12.5px">
                                <?= e(ucfirst((string) $option['room_type'])) ?> room ·
                                <?= e($option['property_name'] ?: $option['location']) ?>
                            </div>
                            <?php if ((int) $option['unit_id'] > 0 && (int) $option['unit_id'] === (int) $room['unit_id']): ?>
                                <span class="belive-badge">same house as yours</span>
                            <?php elseif ((int) $option['property_id'] > 0 && (int) $option['property_id'] === (int) $room['property_id']): ?>
                                <span class="belive-badge">same property as yours</span>
                            <?php endif; ?>
                            <div class="tenant-similar-price">
                                RM <?= e(number_format((float) $option['price_at_tenure'])) ?><span>/mo · <?= e(Room::TENURE_LABELS[$tenure] ?? $tenure) ?></span>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
            <p class="belive-muted" style="font-size:12px; margin-top:12px">
                Every one of these is in <?= e($room['location']) ?> and available now. Prices shown at your own stay
                length, RM 0 deposit — refer a friend to one and your rent rewards grow.
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php portal_footer();
