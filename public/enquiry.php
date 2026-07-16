<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

/**
 * Public website smart enquiry form (proposal channel #2). Deliberately
 * minimal — the real conversation happens on WhatsApp, where Eve follows up
 * instantly after submission.
 */

use App\Pipeline\LeadGeneration\WebsiteFormHandler;

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = WebsiteFormHandler::handle([
        'name'     => $_POST['name'] ?? '',
        'wa_phone' => $_POST['wa_phone'] ?? '',
        'message'  => $_POST['message'] ?? '',
        'ref_code' => $_POST['ref_code'] ?? ($_GET['ref'] ?? ''),
    ]);
}

$e = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#F5833C">
<title>Find your room · beLive</title>
<link rel="stylesheet" href="/assets/css/belive-theme.css">
<style>
    /* page-scoped layout only — all colours come from the theme tokens */
    .enquiry-wrap { max-width: 520px; margin: 8vh auto; padding: 0 16px; }
</style>
</head>
<body>
<div class="enquiry-wrap">
    <div style="text-align:center; margin-bottom:22px">
        <div class="belive-wordmark" style="font-size:34px"><span class="be">be</span><span class="live">Live</span></div>
        <h1 style="font-size:22px; margin-top:10px">The smarter way to rent</h1>
        <p class="belive-muted" style="margin-top:6px">Tell us what you need — Eve, our AI assistant, replies on WhatsApp within seconds.</p>
    </div>

    <div class="belive-card">
        <?php if ($result !== null && $result['ok']): ?>
            <div class="belive-alert success">
                ✓ Got it! Check your WhatsApp — Eve is already looking for your room.
            </div>
            <ul class="belive-check-list">
                <li>Fully furnished rooms</li>
                <li>Zero deposit</li>
                <li>Weekly cleaning service</li>
            </ul>
            <p style="margin-top:12px; font-size:14px" class="belive-muted">Just bring your bag — we handle the rest.</p>
        <?php else: ?>
            <?php if ($result !== null && !$result['ok']): ?>
                <div class="belive-alert danger"><?= $e($result['error']) ?></div>
            <?php endif; ?>
            <form method="post" action="/enquiry<?= isset($_GET['ref']) ? '?ref=' . $e($_GET['ref']) : '' ?>">
                <?php if (isset($_GET['ref'])): ?>
                    <input type="hidden" name="ref_code" value="<?= $e($_GET['ref']) ?>">
                    <div class="belive-alert success" style="font-size:13px">🎁 You arrived through a friend's referral link — they earn rewards when you book!</div>
                <?php endif; ?>
                <div class="belive-field">
                    <label for="name">Your name</label>
                    <input id="name" name="name" type="text" required value="<?= $e($_POST['name'] ?? '') ?>">
                </div>
                <div class="belive-field">
                    <label for="wa_phone">WhatsApp number</label>
                    <input id="wa_phone" name="wa_phone" type="tel" placeholder="60123456789" required value="<?= $e($_POST['wa_phone'] ?? '') ?>">
                    <div class="hint">Eve replies here — digits only, with country code.</div>
                </div>
                <div class="belive-field">
                    <label for="message">What are you looking for?</label>
                    <textarea id="message" name="message" rows="3" required
                        placeholder="e.g. Medium room in Setapak, budget RM700, moving in August"><?= $e($_POST['message'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="belive-btn-primary" style="width:100%; justify-content:center">
                    Find It. Book It. Live In It.
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
