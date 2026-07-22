<?php

declare(strict_types=1);

/**
 * Generates the "photo coming soon" gallery placeholders, one per room_type,
 * into public/assets/img/rooms/placeholders/.
 *
 *   php database/generate_room_placeholders.php            → write the SVGs only
 *   php database/generate_room_placeholders.php --attach   → also give every
 *                                                            photoless room its
 *                                                            type's placeholder
 *
 * These are deliberately line-art SVGs, not photographs: a listing carries a
 * real address and a real price, so a stock photo of some other unit would
 * read as a picture of the room being sold. A drawing cannot be mistaken for
 * one. Replace them per room through the owner/admin upload flow
 * (RoomPhotoManager) as real photography comes in.
 *
 * Palette is the brand's own (public/assets/css/belive-theme.css) — no new hues.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}

define('APP_ROOT', dirname(__DIR__));

const OUT_DIR = APP_ROOT . '/public/assets/img/rooms/placeholders';

const CREAM = '#FAF5F1';
const LINE  = '#EDE4DC';
const INK   = '#1F2937';
const MUTED = '#6B7280';
const TEAL  = '#40C0BF';
const ORANGE = '#F5833C';

/**
 * Per-type line art. Each returns SVG drawn inside a 320x200 box at (240,150),
 * so the shapes differ enough that the type is readable at card size.
 */
function icon(string $type): string
{
    $bed = static fn(int $x, int $y, int $w, int $h): string => sprintf(
        '<rect x="%d" y="%d" width="%d" height="%d" rx="6" fill="none" stroke="%s" stroke-width="4"/>' .
        '<rect x="%d" y="%d" width="%d" height="%d" rx="4" fill="%s" opacity="0.35"/>',
        $x, $y, $w, $h, TEAL,
        $x + 8, $y + 8, (int) ($w * 0.34), (int) ($h * 0.34), TEAL
    );

    return match ($type) {
        // Big bed, plus an ensuite door — the private-bathroom signal.
        'master' => $bed(0, 60, 200, 120)
            . sprintf('<rect x="228" y="20" width="70" height="160" rx="6" fill="none" stroke="%s" stroke-width="4"/>', ORANGE)
            . sprintf('<circle cx="245" cy="100" r="5" fill="%s"/>', ORANGE),

        // Bed plus a kitchenette block: a studio is a whole dwelling.
        'studio' => $bed(0, 70, 175, 110)
            . sprintf('<rect x="205" y="30" width="105" height="60" rx="6" fill="none" stroke="%s" stroke-width="4"/>', ORANGE)
            . sprintf('<line x1="205" y1="120" x2="310" y2="120" stroke="%s" stroke-width="4" stroke-linecap="round"/>', ORANGE)
            . sprintf('<line x1="240" y1="120" x2="240" y2="180" stroke="%s" stroke-width="4" stroke-linecap="round"/>', ORANGE),

        // Bed plus balustrade.
        'balcony' => $bed(0, 60, 180, 120)
            . sprintf('<line x1="215" y1="60" x2="215" y2="185" stroke="%s" stroke-width="4" stroke-linecap="round"/>', ORANGE)
            . sprintf('<line x1="215" y1="70" x2="315" y2="70" stroke="%s" stroke-width="4" stroke-linecap="round"/>', ORANGE)
            . implode('', array_map(
                static fn(int $x): string => sprintf('<line x1="%d" y1="70" x2="%d" y2="185" stroke="%s" stroke-width="3" stroke-linecap="round"/>', $x, $x, ORANGE),
                [240, 265, 290, 315]
            )),

        // Two beds split by a partition wall.
        'partitioned' => $bed(0, 70, 135, 110)
            . $bed(185, 70, 135, 110)
            . sprintf('<line x1="160" y1="30" x2="160" y2="195" stroke="%s" stroke-width="5" stroke-dasharray="12 9" stroke-linecap="round"/>', ORANGE),

        'middle'      => $bed(35, 65, 175, 115),
        'single'      => $bed(60, 75, 145, 100),
        'mini_single' => $bed(85, 90, 115, 80),
        default       => $bed(60, 75, 145, 100),
    };
}

/**
 * Horizontal offset that centres each icon's own bounding box on x=400. The
 * shapes deliberately differ in width, so a shared translate would leave the
 * narrow ones (mini single) visibly off-centre against the caption.
 */
function iconOffsetX(string $type): int
{
    return match ($type) {
        'master'      => 251,
        'studio'      => 245,
        'balcony'     => 242,
        'partitioned' => 240,
        'middle'      => 278,
        'single'      => 268,
        'mini_single' => 258,
        default       => 268,
    };
}

/** Human label for the caption line. */
function label(string $type): string
{
    return match ($type) {
        'mini_single' => 'Mini single room',
        'middle'      => 'Medium room',
        default       => ucfirst($type) . ' room',
    };
}

$types = ['single', 'middle', 'master', 'studio', 'balcony', 'partitioned', 'mini_single'];

if (!is_dir(OUT_DIR) && !mkdir(OUT_DIR, 0775, true) && !is_dir(OUT_DIR)) {
    exit('Could not create ' . OUT_DIR . "\n");
}

foreach ($types as $type) {
    $svg = sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600" width="800" height="600" role="img" aria-label="%s — photo coming soon">'
        . '<rect width="800" height="600" fill="%s"/>'
        . '<rect x="18" y="18" width="764" height="564" rx="18" fill="none" stroke="%s" stroke-width="3"/>'
        . '<g transform="translate(%d,150)">%s</g>'
        . '<text x="400" y="452" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,Roboto,sans-serif" font-size="34" font-weight="600" fill="%s">Photo coming soon</text>'
        . '<text x="400" y="494" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,Roboto,sans-serif" font-size="23" fill="%s">%s · beLive</text>'
        . '</svg>',
        htmlspecialchars(label($type), ENT_QUOTES),
        CREAM,
        LINE,
        iconOffsetX($type),
        icon($type),
        INK,
        MUTED,
        htmlspecialchars(label($type), ENT_QUOTES)
    );

    $file = OUT_DIR . '/' . str_replace('_', '-', $type) . '.svg';
    file_put_contents($file, $svg);
    echo 'wrote ' . basename($file) . "\n";
}

echo "\nDone: " . count($types) . " placeholders in public/assets/img/rooms/placeholders/\n";

if (!in_array('--attach', $argv, true)) {
    exit(0);
}

// --attach: only ever fills a genuine gap. A room that already has any image —
// a real photograph, or a placeholder from an earlier run — is left untouched,
// so this can never displace real photography.
require APP_ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();

$cfg = require APP_ROOT . '/config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']),
    $cfg['user'],
    $cfg['pass'],
    $cfg['options']
);

$photoless = $pdo->query(
    'SELECT r.id, r.room_type
     FROM rooms r
     WHERE NOT EXISTS (SELECT 1 FROM room_images i WHERE i.room_id = r.id)'
)->fetchAll();

$insert = $pdo->prepare('INSERT INTO room_images (room_id, image_path, sort_order) VALUES (?, ?, 0)');

$attached = 0;
foreach ($photoless as $room) {
    $insert->execute([
        (int) $room['id'],
        '/assets/img/rooms/placeholders/' . str_replace('_', '-', $room['room_type']) . '.svg',
    ]);
    $attached++;
}

echo "Attached placeholders to $attached photoless room(s).\n";

