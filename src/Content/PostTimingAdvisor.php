<?php

declare(strict_types=1);

namespace App\Content;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;

/**
 * When should a post go out?
 *
 * Builds a 7x24 weekly grid (Mon..Sun x 00..23, Asia/Kuala_Lumpur) of how
 * active BeLive's audience is, from three things we actually record:
 *
 *   social_replies   a comment or DM on one of our posts — the only signal
 *                    that is unambiguously a reaction to content   (weight 3)
 *   leads            a new enquiry arriving in that hour           (weight 2)
 *   ai_interactions  any inbound message — the audience is awake   (weight 1)
 *
 * Two deliberate choices, both about not overselling what the data says:
 *
 *  1. SPILL. An engagement at 21:00 was earned by a post that was already in
 *     the feed, so the hour before it gets half the credit. That is why the
 *     advisor tends to suggest posting slightly ahead of the raw peak.
 *
 *  2. SMOOTHING. A handful of events would otherwise make one cell look like a
 *     law of nature. Each cell is blended toward a day x hour independence
 *     model (the hour profile times the weekday profile), and those profiles
 *     are themselves blended toward a documented default curve. With no data
 *     at all you get the default curve; with a few hundred events your own
 *     pattern dominates. confidence() says which of the two you are looking at,
 *     and the studio prints it next to the grid — a suggestion built on 11
 *     events must not read like one built on 1,100.
 */
final class PostTimingAdvisor
{
    /** Grid rows, Monday first (Malaysian work week). */
    public const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    public const TIMEZONE = 'Asia/Kuala_Lumpur';

    private const WEIGHT_SOCIAL = 3.0;
    private const WEIGHT_LEAD = 2.0;
    private const WEIGHT_INBOUND = 1.0;

    /** Credit spread backwards from the engagement hour: this hour, half the hour before. */
    private const SPILL = [0 => 1.0, 1 => 0.5];

    /**
     * Weighted events needed before the observed grid outweighs the smooth
     * model 50/50. Roughly a season of steady traffic.
     */
    private const GRID_PRIOR_STRENGTH = 200.0;

    /** Same idea for the two marginal profiles, which fill up far faster. */
    private const PROFILE_PRIOR_STRENGTH = 40.0;

    /**
     * The default curve, used when we have little or nothing of our own. It is
     * a room-rental audience in KL — students and young working tenants who
     * browse at lunch and again after dinner, with the deepest interest late on
     * Sunday evening when next month's move-in gets decided. Relative weights,
     * normalised on use; deliberately smooth, so it never invents a spike.
     */
    private const HOUR_PROFILE = [
        0 => 0.35, 1 => 0.20, 2 => 0.10, 3 => 0.05, 4 => 0.05, 5 => 0.08,
        6 => 0.20, 7 => 0.40, 8 => 0.60, 9 => 0.70, 10 => 0.75, 11 => 0.80,
        12 => 1.00, 13 => 0.95, 14 => 0.70, 15 => 0.65, 16 => 0.70, 17 => 0.80,
        18 => 0.85, 19 => 0.95, 20 => 1.15, 21 => 1.25, 22 => 1.05, 23 => 0.70,
    ];

    /** Mon..Sun. */
    private const DAY_PROFILE = [0.98, 1.00, 1.02, 1.02, 0.94, 0.92, 1.12];

    /**
     * The weekly grid for a platform (null = every channel together).
     *
     * @return array{
     *   grid: array<int, array<int, array{score: float, events: int, weight: float}>>,
     *   peak: array{day: int, hour: int, score: float},
     *   events: int,
     *   weight: float,
     *   sources: array{social: int, leads: int, inbound: int},
     *   observed_share: float,
     *   confidence: string,
     *   window_days: int
     * }
     */
    public static function heatmap(?string $platform = null, int $windowDays = CONTENT_TIMING_WINDOW_DAYS): array
    {
        $windowDays = max(7, min(365, $windowDays));
        [$weights, $events, $sources] = self::collect($platform, $windowDays);

        $totalWeight = 0.0;
        $totalEvents = 0;
        foreach ($weights as $row) {
            $totalWeight += array_sum($row);
        }
        foreach ($events as $row) {
            $totalEvents += array_sum($row);
        }

        // Marginal profiles: our own hour-of-day and day-of-week shape, each
        // pulled toward the default curve while it is still thin.
        $hourShare = self::profile(self::marginal($weights, 'hour'), self::HOUR_PROFILE, $totalWeight);
        $dayShare = self::profile(self::marginal($weights, 'day'), self::DAY_PROFILE, $totalWeight);

        // How much of the final grid is our own cell-level data.
        $observedShare = $totalWeight / ($totalWeight + self::GRID_PRIOR_STRENGTH);

        $grid = [];
        $peak = ['day' => 0, 'hour' => 0, 'score' => 0.0];
        $best = 0.0;
        $raw = [];
        foreach (array_keys(self::DAYS) as $day) {
            for ($hour = 0; $hour < 24; $hour++) {
                $modelled = $dayShare[$day] * $hourShare[$hour];
                $observed = $totalWeight > 0.0 ? $weights[$day][$hour] / $totalWeight : 0.0;
                $share = $observedShare * $observed + (1.0 - $observedShare) * $modelled;
                $raw[$day][$hour] = $share;
                $best = max($best, $share);
            }
        }

        // Displayed score is the share against the best slot of the week, so
        // 1.00 always means "the strongest hour we know of" whatever the volume.
        foreach ($raw as $day => $hours) {
            foreach ($hours as $hour => $share) {
                $score = $best > 0.0 ? $share / $best : 0.0;
                $grid[$day][$hour] = [
                    'score'  => round($score, 4),
                    'events' => $events[$day][$hour],
                    'weight' => round($weights[$day][$hour], 2),
                ];
                if ($score > $peak['score']) {
                    $peak = ['day' => $day, 'hour' => $hour, 'score' => $score];
                }
            }
        }

        return [
            'grid'           => $grid,
            'peak'           => $peak,
            'events'         => $totalEvents,
            'weight'         => round($totalWeight, 1),
            'sources'        => $sources,
            'observed_share' => round($observedShare, 3),
            'confidence'     => self::confidence($totalEvents),
            'window_days'    => $windowDays,
        ];
    }

    /**
     * The best slots to post, strongest first. Never two on the same weekday
     * and never within three hours of a slot already picked — an admin choosing
     * between three suggestions wants three real options, not one peak sliced
     * into thirds.
     *
     * @param array<string, mixed>|null $heatmap reuse a grid already built
     * @return list<array{
     *   day: int, hour: int, score: float, events: int, label: string,
     *   next: DateTimeImmutable, next_label: string, why: string
     * }>
     */
    public static function suggestions(?string $platform = null, int $count = 3, ?array $heatmap = null): array
    {
        $heatmap ??= self::heatmap($platform);
        $count = max(1, min(7, $count));

        $slots = [];
        foreach ($heatmap['grid'] as $day => $hours) {
            foreach ($hours as $hour => $cell) {
                $slots[] = ['day' => $day, 'hour' => $hour] + $cell;
            }
        }
        usort($slots, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $picked = [];
        foreach ($slots as $slot) {
            if (count($picked) >= $count) {
                break;
            }
            foreach ($picked as $taken) {
                if ($taken['day'] === $slot['day'] || self::hoursApart($taken['hour'], $slot['hour']) < 3) {
                    continue 2;
                }
            }
            $next = self::nextOccurrence($slot['day'], $slot['hour']);
            $picked[] = [
                'day'        => $slot['day'],
                'hour'       => $slot['hour'],
                'score'      => $slot['score'],
                'events'     => $slot['events'],
                'label'      => self::slotLabel($slot['day'], $slot['hour']),
                'next'       => $next,
                'next_label' => $next->format('D j M, H:i'),
                'why'        => self::why($slot, $heatmap),
            ];
        }

        return $picked;
    }

    /** Human sentence for a suggested slot, honest about how thin the evidence is. */
    private static function why(array $slot, array $heatmap): string
    {
        $day = self::DAYS[$slot['day']];
        if ($heatmap['events'] === 0) {
            return "No engagement recorded yet — $day is the default curve's strongest slot, and it will move as real data arrives.";
        }
        if ($slot['events'] === 0) {
            return "No events landed in this exact hour, but $day and " . self::hourLabel($slot['hour'])
                . ' are each strong across the week.';
        }

        return sprintf(
            '%d engagement event%s landed in this slot over the last %d days.',
            $slot['events'],
            $slot['events'] === 1 ? '' : 's',
            $heatmap['window_days']
        );
    }

    public static function slotLabel(int $day, int $hour): string
    {
        return self::DAYS[$day] . ' ' . self::hourLabel($hour);
    }

    public static function hourLabel(int $hour): string
    {
        return sprintf('%02d:00', $hour);
    }

    /** Shortest distance between two hours on a 24h clock (23:00 and 01:00 are 2 apart). */
    private static function hoursApart(int $a, int $b): int
    {
        $diff = abs($a - $b);

        return min($diff, 24 - $diff);
    }

    /**
     * The next time this weekday/hour comes round, at least
     * CONTENT_SCHEDULE_MIN_LEAD_MINUTES away so the publisher cron can reach it.
     */
    public static function nextOccurrence(int $day, int $hour, ?DateTimeImmutable $from = null): DateTimeImmutable
    {
        $from ??= self::now();
        $earliest = $from->modify('+' . CONTENT_SCHEDULE_MIN_LEAD_MINUTES . ' minutes');

        // Monday-first index -> PHP's 0=Sunday.
        $candidate = $earliest->setTime($hour, 0)->modify('-7 days');
        for ($i = 0; $i <= 14; $i++) {
            $step = $candidate->modify("+$i days");
            if (((int) $step->format('N')) - 1 === $day && $step > $earliest) {
                return $step;
            }
        }

        return $earliest->setTime($hour, 0)->modify('+7 days');
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));
    }

    private static function confidence(int $events): string
    {
        return match (true) {
            $events >= 150 => 'high',
            $events >= 40  => 'medium',
            default        => 'low',
        };
    }

    /**
     * Read the three sources into weighted and raw-count grids.
     *
     * @return array{0: array<int, array<int, float>>, 1: array<int, array<int, int>>, 2: array{social: int, leads: int, inbound: int}}
     */
    private static function collect(?string $platform, int $windowDays): array
    {
        $weights = [];
        $events = [];
        foreach (array_keys(self::DAYS) as $day) {
            $weights[$day] = array_fill(0, 24, 0.0);
            $events[$day] = array_fill(0, 24, 0);
        }
        $sources = ['social' => 0, 'leads' => 0, 'inbound' => 0];

        $add = static function (array $rows, float $weight, string $source) use (&$weights, &$events, &$sources): void {
            foreach ($rows as $row) {
                // MySQL DAYOFWEEK is 1=Sunday; the grid is Monday-first.
                $day = ((int) $row['dow'] + 5) % 7;
                $hour = (int) $row['hr'];
                $count = (int) $row['c'];
                $sources[$source] += $count;
                $events[$day][$hour] += $count;

                foreach (self::SPILL as $back => $share) {
                    $slotHour = ($hour - $back + 24) % 24;
                    // Crossing midnight backwards lands on the previous day.
                    $slotDay = $slotHour > $hour ? ($day + 6) % 7 : $day;
                    $weights[$slotDay][$slotHour] += $count * $weight * $share;
                }
            }
        };

        // Comments and DMs on our posts. TikTok has no reply webhook, so its
        // view leans on enquiry volume instead of pretending otherwise.
        if ($platform !== 'tiktok') {
            $where = $platform === null ? '' : ' AND platform = ?';
            $add(self::query(
                "SELECT DAYOFWEEK(created_at) AS dow, HOUR(created_at) AS hr, COUNT(*) AS c
                 FROM social_replies
                 WHERE created_at >= (NOW() - INTERVAL $windowDays DAY)$where
                 GROUP BY dow, hr",
                $platform === null ? [] : [$platform]
            ), self::WEIGHT_SOCIAL, 'social');
        }

        // New enquiries. A TikTok view counts only the channels TikTok can
        // produce; every other view counts the whole funnel.
        $leadWhere = $platform === 'tiktok' ? " AND source_channel IN ('tiktok', 'social')" : '';
        $add(self::query(
            "SELECT DAYOFWEEK(created_at) AS dow, HOUR(created_at) AS hr, COUNT(*) AS c
             FROM leads
             WHERE created_at >= (NOW() - INTERVAL $windowDays DAY)$leadWhere
             GROUP BY dow, hr"
        ), self::WEIGHT_LEAD, 'leads');

        // Inbound messages — the audience is at their phone.
        $add(self::query(
            "SELECT DAYOFWEEK(created_at) AS dow, HOUR(created_at) AS hr, COUNT(*) AS c
             FROM ai_interactions
             WHERE direction = 'inbound' AND created_at >= (NOW() - INTERVAL $windowDays DAY)
             GROUP BY dow, hr"
        ), self::WEIGHT_INBOUND, 'inbound');

        return [$weights, $events, $sources];
    }

    /**
     * A missing table (an install part-way through migrations) must not take
     * the content studio down — an empty grid falls back to the default curve.
     *
     * @return list<array<string, mixed>>
     */
    private static function query(string $sql, array $params = []): array
    {
        try {
            return Database::run($sql, $params)->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<int, array<int, float>> $weights
     * @return array<int, float> totals per day or per hour
     */
    private static function marginal(array $weights, string $axis): array
    {
        if ($axis === 'day') {
            return array_map(static fn (array $hours): float => array_sum($hours), $weights);
        }

        $totals = array_fill(0, 24, 0.0);
        foreach ($weights as $hours) {
            foreach ($hours as $hour => $weight) {
                $totals[$hour] += $weight;
            }
        }

        return $totals;
    }

    /**
     * Observed totals blended with the default curve, returned as shares that
     * sum to 1.
     *
     * @param array<int, float> $observed
     * @param array<int, float> $default
     * @return array<int, float>
     */
    private static function profile(array $observed, array $default, float $totalWeight): array
    {
        $defaultSum = array_sum($default);
        $observedShare = $totalWeight / ($totalWeight + self::PROFILE_PRIOR_STRENGTH);

        $blended = [];
        $sum = 0.0;
        foreach ($default as $key => $weight) {
            $modelled = $defaultSum > 0.0 ? $weight / $defaultSum : 0.0;
            $seen = $totalWeight > 0.0 ? ($observed[$key] ?? 0.0) / $totalWeight : 0.0;
            $blended[$key] = $observedShare * $seen + (1.0 - $observedShare) * $modelled;
            $sum += $blended[$key];
        }

        if ($sum <= 0.0) {
            return array_fill_keys(array_keys($default), 1.0 / max(1, count($default)));
        }

        return array_map(static fn (float $value): float => $value / $sum, $blended);
    }
}
