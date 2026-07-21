<?php

declare(strict_types=1);

/**
 * Terms of Service + Privacy Policy — required by the TikTok, Meta and
 * WhatsApp developer app submissions (reviewers open these URLs directly, so
 * they must render publicly with no login). One file serves both documents;
 * the router passes the document name.
 */

defined('APP_BOOTED') || exit('No direct access.');

require __DIR__ . '/_site_layout.php';

$doc = ($_GET['doc'] ?? 'terms') === 'privacy' ? 'privacy' : 'terms';
$updated = '21 July 2026';
$contact = $_ENV['LEGAL_CONTACT_EMAIL'] ?? 'wongyunxuan10@gmail.com';

site_header($doc === 'privacy' ? 'Privacy Policy' : 'Terms of Service');
?>
<section class="site-section" style="max-width:820px; margin:0 auto">
<style>
.legal h2 { margin-top:32px }
.legal h3 { margin-top:22px; font-size:16px }
.legal p, .legal li { line-height:1.7; color:var(--belive-muted) }
.legal ul { padding-left:22px }
</style>
<div class="legal">

<?php if ($doc === 'privacy'): ?>

    <h2>Privacy Policy</h2>
    <p><em>Last updated: <?= e($updated) ?></em></p>

    <p>This policy explains how the BeLive Automation Engine ("the Service",
    "we") handles personal data. The Service is a rental-management platform
    operated by team GrenA (TAR UMT Johor) for the BeLive &times; TAR UMT AI
    Solopreneur Challenge 2026.</p>

    <h3>1. Information we collect</h3>
    <ul>
        <li><strong>Enquiry details</strong> you submit yourself: name, phone
        number, email, preferred location, budget and move-in date.</li>
        <li><strong>Messages</strong> you send us over WhatsApp or social
        media comments, together with our replies.</li>
        <li><strong>Tenancy records</strong> for confirmed tenants: room
        assigned, rent, payment history and maintenance requests.</li>
        <li><strong>Connected social accounts.</strong> When an administrator
        links a TikTok, Facebook or Instagram account to the Service, we
        receive an access token plus that account's basic profile information
        (display name, avatar, open ID) from the platform.</li>
    </ul>

    <h3>2. How we use it</h3>
    <ul>
        <li>To answer your enquiry and match you to suitable rooms.</li>
        <li>To manage your tenancy, rent reminders and maintenance requests.</li>
        <li>To publish marketing content, on the account owner's instruction,
        to the social accounts they have explicitly connected. We only post
        content the account owner has reviewed and approved. We never post
        without an explicit action, and we never read or collect other users'
        TikTok content.</li>
        <li>To generate internal operational reports.</li>
    </ul>

    <h3>3. Third-party services</h3>
    <p>We share the minimum data necessary with: TikTok (Content Posting API,
    to publish content you approve), Meta / WhatsApp Business (to send and
    receive messages), Google Gemini and Anthropic Claude (to draft message
    replies and marketing copy), and Microsoft Azure (hosting). Each processes
    data under its own privacy policy.</p>

    <h3>4. Retention and deletion</h3>
    <p>Enquiry records are kept for 24 months; tenancy records for 7 years as
    required for accounting. Social access tokens are deleted immediately when
    the account is disconnected. You may request access to, correction of, or
    deletion of your personal data at any time by emailing
    <?= e($contact) ?> — we respond within 30 days.</p>

    <h3>5. Security</h3>
    <p>Data is stored on encrypted infrastructure. API credentials and access
    tokens are encrypted at rest. Access is restricted to authorised
    administrators.</p>

    <h3>6. Children</h3>
    <p>The Service is not directed at anyone under 18 and we do not knowingly
    collect their data.</p>

    <h3>7. Changes</h3>
    <p>We will update this page when our practices change, and revise the date
    above.</p>

    <h3>8. Contact</h3>
    <p>Questions or data requests: <?= e($contact) ?></p>

<?php else: ?>

    <h2>Terms of Service</h2>
    <p><em>Last updated: <?= e($updated) ?></em></p>

    <p>These terms govern your use of the BeLive Automation Engine ("the
    Service"), a rental-management platform operated by team GrenA (TAR UMT
    Johor). By using the Service you agree to them.</p>

    <h3>1. What the Service does</h3>
    <p>The Service lists rental rooms, handles enquiries over WhatsApp and the
    web, manages tenancy records, and — where an administrator has connected a
    social account — publishes marketing content that the account owner has
    reviewed and approved.</p>

    <h3>2. Your responsibilities</h3>
    <ul>
        <li>Provide accurate information when making an enquiry or signing a
        tenancy.</li>
        <li>Do not use the Service unlawfully, or attempt to disrupt, probe or
        gain unauthorised access to it.</li>
        <li>If you connect a social media account, you confirm you are
        authorised to act for that account and that content you publish
        through the Service complies with that platform's rules.</li>
    </ul>

    <h3>3. Connected social accounts</h3>
    <p>You may disconnect any linked account at any time; we delete the stored
    access token on disconnection. You remain responsible for content
    published through your account. We do not post without your approval.</p>

    <h3>4. Listings and pricing</h3>
    <p>Room availability and prices shown are indicative and may change. A
    booking is only confirmed once a tenancy agreement is signed.</p>

    <h3>5. Availability</h3>
    <p>The Service is provided "as is" without warranty of uninterrupted
    availability. This is a demonstration build produced for a competition and
    may be taken offline.</p>

    <h3>6. Liability</h3>
    <p>To the extent permitted by Malaysian law, we are not liable for
    indirect or consequential loss arising from use of the Service.</p>

    <h3>7. Governing law</h3>
    <p>These terms are governed by the laws of Malaysia.</p>

    <h3>8. Contact</h3>
    <p><?= e($contact) ?></p>

<?php endif; ?>

<p style="margin-top:36px">
    <a href="/<?= $doc === 'privacy' ? 'terms' : 'privacy' ?>">
        Read our <?= $doc === 'privacy' ? 'Terms of Service' : 'Privacy Policy' ?> →
    </a>
</p>
</div>
</section>
<?php
site_footer();
