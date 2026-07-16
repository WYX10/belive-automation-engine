<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

use App\Catalog\RoomRepository;

require __DIR__ . '/_site_layout.php';

$featured = RoomRepository::featured(6);
$locations = RoomRepository::locations();

site_header('The smarter way to rent', 'home');
?>
<section class="site-hero">
    <h1>Find It. Book It. <span class="accent">Live In It.</span></h1>
    <p>Fully furnished rooms. Zero deposit. Weekly cleaning. Just bring your bag — we handle the rest.</p>
    <div class="cta-row">
        <a class="belive-btn-primary" href="<?= e(eve_whatsapp_link()) ?>" target="_blank" rel="noopener">💬 Chat with us on WhatsApp</a>
        <a class="belive-btn-secondary" href="/rooms">Browse rooms</a>
    </div>
    <div style="margin-top:38px">
        <img src="/assets/img/rooms/facility-pool.jpg" alt="BeLive residence — pool and facilities"
             style="width:100%; max-height:400px; object-fit:cover; border-radius:var(--belive-radius); box-shadow:var(--belive-shadow-lift)">
    </div>
</section>

<div class="belive-stat-grid">
    <div class="belive-stat"><div class="belive-stat-icon">🏠</div><div><div class="belive-stat-number">3,000+</div><div class="belive-stat-label">Rooms</div></div></div>
    <div class="belive-stat"><div class="belive-stat-icon teal">🚗</div><div><div class="belive-stat-number teal">1,500+</div><div class="belive-stat-label">Carparks</div></div></div>
    <div class="belive-stat"><div class="belive-stat-icon">🏢</div><div><div class="belive-stat-number">900+</div><div class="belive-stat-label">Units</div></div></div>
    <div class="belive-stat"><div class="belive-stat-icon teal">🏙</div><div><div class="belive-stat-number teal">53+</div><div class="belive-stat-label">Condos</div></div></div>
</div>

<section class="site-section">
    <h2>Featured rooms</h2>
    <p class="sub">Every price shown with its stay length — no hidden fees, no surprises, everything clearly stated from day one.</p>
    <div class="room-grid">
        <?php foreach ($featured as $room) { room_card($room); } ?>
    </div>
    <div style="text-align:center; margin-top:26px">
        <a class="belive-btn-ghost" href="/rooms">See all rooms →</a>
    </div>
</section>

<section class="site-section">
    <h2>Popular locations</h2>
    <p class="sub">Rooms where you actually want to live — near campus, LRT and the city.</p>
    <div class="loc-grid">
        <?php foreach ($locations as $loc): ?>
            <a class="loc-card" href="/rooms?location=<?= e(urlencode($loc['location'])) ?>">
                <div style="font-family:var(--font-head); font-weight:600"><?= e($loc['location']) ?></div>
                <div class="n"><?= (int) $loc['n'] ?> room<?= (int) $loc['n'] === 1 ? '' : 's' ?> available</div>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="site-section">
    <div class="belive-row">
        <div class="belive-col">
            <div class="belive-card">
                <h2 style="font-size:20px; margin-bottom:12px">Why renters choose beLive</h2>
                <ul class="belive-check-list">
                    <li>Fully Furnished</li>
                    <li>High-speed WiFi</li>
                    <li>Weekly Cleaning Service</li>
                    <li>High-end Facilities</li>
                    <li>24/7 Support</li>
                </ul>
            </div>
        </div>
        <div class="belive-col">
            <div class="belive-card" style="display:flex; flex-direction:column; justify-content:center; height:100%">
                <h2 style="font-size:20px">Malaysia's 1st: <span style="color:var(--belive-orange)">0 Security Deposit</span></h2>
                <p class="belive-muted" style="margin:10px 0 16px; font-size:14.5px">
                    Keep your savings. Move in with nothing but your bag — every room on this site is zero deposit.
                </p>
                <div>
                    <a class="belive-btn-primary" href="<?= e(eve_whatsapp_link()) ?>" target="_blank" rel="noopener">Ask Eve anything — 24/7</a>
                </div>
            </div>
        </div>
    </div>
</section>
<?php site_footer();
