<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

/**
 * Shared render helpers for the two engagement surfaces — Admin → Engagement
 * (per post) and Admin → Reports (aggregated). Both print numbers that come
 * from a platform API, and both have to be honest when one is missing.
 *
 * The distinction these helpers exist to protect: NULL is not zero. A metric
 * the platform never gave us renders as a dim "—" carrying the reason in its
 * tooltip; a genuine zero renders as "0". Nothing on either page can quietly
 * turn "we could not measure this" into "this performed badly".
 */

const METRIC_PLATFORM_ICONS = ['facebook' => '📘', 'instagram' => '📷', 'tiktok' => '🎵'];

function metric_platform_icon(?string $platform): string
{
    return METRIC_PLATFORM_ICONS[$platform ?? ''] ?? '📣';
}

/** A counter, or a dim dash explaining itself. */
function metric_number(?int $value, string $whyMissing = 'The platform did not report this number.'): string
{
    if ($value === null) {
        return '<span class="metric-missing" title="' . e($whyMissing) . '">—</span>';
    }

    return '<span class="metric-number">' . e(number_format($value)) . '</span>';
}

/** Interactions per viewer, as a percentage — null when viewers are unknown. */
function metric_rate(?float $rate): string
{
    if ($rate === null) {
        return '<span class="metric-missing" title="Needs a viewer count to divide by.">—</span>';
    }

    return '<span class="metric-number">' . e(number_format($rate, 2)) . '%</span>';
}

/**
 * Why a row has no numbers, in one sentence. The collector already writes a
 * `note` when it knows; this covers the row it has never looked at.
 *
 * @param array<string, mixed> $row a post joined to its latest snapshot
 */
function metric_gap_reason(array $row): string
{
    if (($row['note'] ?? null) !== null && $row['note'] !== '') {
        return (string) $row['note'];
    }
    if (($row['captured_at'] ?? null) === null) {
        return 'Not checked yet — hit Refresh to read this post\'s numbers off the platform.';
    }

    return 'The platform did not report this number.';
}

/**
 * The plain-English half of a gap reason, without the raw Graph body the
 * collector appends for traceability. Two posts refused by the same missing
 * permission carry different API tails, and grouping on the whole string would
 * list one cause twice — the tail belongs in the tooltip, not the headline.
 */
function metric_gap_headline(string $note): string
{
    $cut = mb_strpos($note, 'Graph read failed');
    $headline = $cut === false ? $note : rtrim(mb_substr($note, 0, $cut));

    return $headline === '' ? $note : $headline;
}

/**
 * The four counters as table cells, in one place so the engagement table and
 * the report tables can never disagree about what a gap looks like.
 *
 * @param array<string, mixed> $row
 */
function metric_cells(array $row): void
{
    $reason = metric_gap_reason($row);
    foreach (App\Content\EngagementCollector::METRICS as $metric) {
        $value = $row[$metric] ?? null;
        echo '<td class="metric-cell">'
            . metric_number($value === null ? null : (int) $value, $reason)
            . '</td>';
    }
}

/**
 * The badge that says where a row's numbers came from — or why there are none.
 *
 * @param array<string, mixed> $row
 */
function metric_source_badge(array $row): string
{
    if (($row['captured_at'] ?? null) === null) {
        return '<span class="belive-badge muted" title="This post has never been polled.">not checked</span>';
    }
    if (($row['source'] ?? '') === 'platform') {
        $badge = '<span class="belive-badge" title="Read from the platform\'s own API on '
            . e((string) $row['captured_at']) . '.">live</span>';

        // Partial reads are common (Page insights are their own permission) —
        // say so rather than letting a dash look like an outage.
        return ($row['note'] ?? null) ? $badge . ' <span class="belive-badge orange" title="'
            . e((string) $row['note']) . '">partial</span>' : $badge;
    }

    return '<span class="belive-badge orange" title="' . e(metric_gap_reason($row)) . '">no data</span>';
}
