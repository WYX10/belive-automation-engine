<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

/**
 * Shared render helpers for the posting-time advisor: the one-week heatmap
 * (content studio) and the slot picker (post preview). Both read the same
 * PostTimingAdvisor output, so the time an admin picks is the same time the
 * grid recommends.
 */

use App\Content\PostTimingAdvisor;

/**
 * One week, 7 rows x 24 hourly cells, shaded by slot score. Suggested slots
 * carry a teal ring; the hour we are in right now carries a dark one.
 *
 * @param array<string, mixed> $heatmap
 * @param list<array<string, mixed>> $suggestions
 */
function timing_heatmap(array $heatmap, array $suggestions = []): void
{
    $picks = [];
    foreach ($suggestions as $rank => $slot) {
        $picks["{$slot['day']}:{$slot['hour']}"] = $rank + 1;
    }

    $now = PostTimingAdvisor::now();
    $nowDay = ((int) $now->format('N')) - 1;
    $nowHour = (int) $now->format('G');
    ?>
    <div class="timing-scroll">
        <div class="timing-grid">
            <div></div>
            <?php for ($hour = 0; $hour < 24; $hour++): ?>
                <div class="timing-grid-hour"><?= $hour % 3 === 0 ? str_pad((string) $hour, 2, '0', STR_PAD_LEFT) : '' ?></div>
            <?php endfor; ?>

            <?php foreach (PostTimingAdvisor::DAYS as $day => $dayName): ?>
                <div class="timing-grid-label"><?= e($dayName) ?></div>
                <?php for ($hour = 0; $hour < 24; $hour++):
                    $cell = $heatmap['grid'][$day][$hour];
                    $rank = $picks["$day:$hour"] ?? null;
                    $classes = 'timing-cell'
                        . ($rank !== null ? ' is-pick' : '')
                        . ($day === $nowDay && $hour === $nowHour ? ' is-now' : '');
                    $title = sprintf(
                        '%s — %d%% of the best slot, %d engagement event%s%s',
                        PostTimingAdvisor::slotLabel($day, $hour),
                        (int) round($cell['score'] * 100),
                        $cell['events'],
                        $cell['events'] === 1 ? '' : 's',
                        $rank !== null ? " · suggestion #$rank" : ''
                    );
                    // Floor the tint so a strong slot is visibly strong without
                    // the weak ones vanishing into the card background.
                    $intensity = round(0.06 + 0.88 * $cell['score'], 3);
                    ?>
                    <div class="<?= $classes ?>" style="--i:<?= $intensity ?>" title="<?= e($title) ?>"></div>
                <?php endfor; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="timing-legend" style="margin-top:8px">
        <span>Quieter</span>
        <span class="timing-legend-scale">
            <?php foreach ([0.06, 0.28, 0.5, 0.72, 0.94] as $step): ?><span style="--i:<?= $step ?>"></span><?php endforeach; ?>
        </span>
        <span>Busier</span>
        <span style="margin-left:8px">▫ teal ring = suggested slot · dark ring = right now</span>
    </div>
    <?php
}

/**
 * The honest footnote: what the grid was actually built from. A suggestion
 * drawn from eleven events must never read like one drawn from eleven hundred.
 *
 * @param array<string, mixed> $heatmap
 */
function timing_basis(array $heatmap): string
{
    $sources = $heatmap['sources'];
    $ownData = (int) round($heatmap['observed_share'] * 100);

    if ($heatmap['events'] === 0) {
        return 'No engagement recorded in the last ' . (int) $heatmap['window_days']
            . ' days yet, so this is the default rental-audience curve — it starts reshaping itself'
            . ' the moment comments, enquiries and messages come in.';
    }

    return sprintf(
        '%s confidence — built from %s engagement event%s in the last %d days '
        . '(%d comment/DM, %d new lead%s, %d inbound message%s). '
        . '%d%% of the shading is your own hour-by-hour data%s.',
        ucfirst((string) $heatmap['confidence']),
        number_format($heatmap['events']),
        $heatmap['events'] === 1 ? '' : 's',
        (int) $heatmap['window_days'],
        $sources['social'],
        $sources['leads'],
        $sources['leads'] === 1 ? '' : 's',
        $sources['inbound'],
        $sources['inbound'] === 1 ? '' : 's',
        $ownData,
        $ownData < 50 ? ', the rest the default curve holding the shape steady' : ''
    );
}
