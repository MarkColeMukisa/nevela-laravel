<?php

namespace Nevela\Laravel\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The periods a "created per day, week or month" chart is drawn in.
 *
 * The database is only ever asked for counts per day, which every database can give
 * with the same expression. Weeks and months are added up here, so a week starts on
 * Monday and a month is a calendar month whichever database is underneath.
 */
final class Insights
{
    /** How many periods of each kind a chart shows: a month of days, half a year of weeks, a year of months. */
    public const PERIODS = ['day' => 30, 'week' => 26, 'month' => 12];

    /**
     * The periods up to and including the one `$now` is in, oldest first.
     *
     * @return list<string> A day or a week as "2026-10-05" (a week by its Monday), a month as "2026-10"
     */
    public static function buckets(string $unit, CarbonInterface $now): array
    {
        $current = CarbonImmutable::instance($now)->startOfDay();
        $buckets = [];
        for ($back = self::PERIODS[$unit] - 1; $back >= 0; $back--) {
            $buckets[] = match ($unit) {
                'day' => $current->subDays($back)->format('Y-m-d'),
                'week' => $current->startOfWeek(CarbonInterface::MONDAY)->subWeeks($back)->format('Y-m-d'),
                // From the first of the month: stepping back from the 31st would skip the short months.
                'month' => $current->startOfMonth()->subMonthsNoOverflow($back)->format('Y-m'),
            };
        }

        return $buckets;
    }

    /** The first moment the chart covers. */
    public static function since(string $unit, CarbonInterface $now): CarbonImmutable
    {
        $first = self::buckets($unit, $now)[0];

        return CarbonImmutable::parse($unit === 'month' ? "{$first}-01" : $first, $now->getTimezone())->startOfDay();
    }

    /** Which period a day ("2026-10-09") belongs to. */
    public static function bucketOf(string $unit, string $day): string
    {
        return match ($unit) {
            'day' => substr($day, 0, 10),
            'week' => CarbonImmutable::parse(substr($day, 0, 10))->startOfWeek(CarbonInterface::MONDAY)->format('Y-m-d'),
            'month' => substr($day, 0, 7),
        };
    }

    /**
     * Counts per day, added up into the chart's periods. A period with nothing in it is
     * still there, as zero: a gap in a chart should look like a gap.
     *
     * @param  iterable<array{0: string, 1: int}>  $perDay  Pairs of day and count
     * @return list<array{bucket: string, count: int}>
     */
    public static function series(string $unit, CarbonInterface $now, iterable $perDay): array
    {
        $counts = array_fill_keys(self::buckets($unit, $now), 0);
        foreach ($perDay as [$day, $count]) {
            $bucket = self::bucketOf($unit, $day);
            if (isset($counts[$bucket])) {
                $counts[$bucket] += $count;
            }
        }

        return array_map(fn (string $bucket, int $count) => ['bucket' => $bucket, 'count' => $count], array_keys($counts), array_values($counts));
    }
}
