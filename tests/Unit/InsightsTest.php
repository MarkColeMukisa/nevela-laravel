<?php

namespace Nevela\Laravel\Tests\Unit;

use Carbon\CarbonImmutable;
use Nevela\Laravel\Support\Insights;
use PHPUnit\Framework\TestCase;

final class InsightsTest extends TestCase
{
    public function test_a_chart_is_thirty_days_or_twenty_six_weeks_or_twelve_months_ending_now(): void
    {
        // A Friday.
        $now = CarbonImmutable::parse('2026-10-09 15:30:00', 'UTC');

        $days = Insights::buckets('day', $now);
        $this->assertCount(30, $days);
        $this->assertSame('2026-09-10', $days[0]);
        $this->assertSame('2026-10-09', $days[29]);

        // A week is called by its Monday.
        $weeks = Insights::buckets('week', $now);
        $this->assertCount(26, $weeks);
        $this->assertSame('2026-10-05', $weeks[25]);
        $this->assertSame('2026-09-28', $weeks[24]);
        $this->assertSame('2026-04-13', $weeks[0]);

        $months = Insights::buckets('month', $now);
        $this->assertSame(['2025-11', '2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'], $months);

        $this->assertSame('2026-09-10 00:00:00', Insights::since('day', $now)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-13 00:00:00', Insights::since('week', $now)->format('Y-m-d H:i:s'));
        $this->assertSame('2025-11-01 00:00:00', Insights::since('month', $now)->format('Y-m-d H:i:s'));
    }

    public function test_no_month_is_skipped_when_today_is_the_thirty_first(): void
    {
        $months = Insights::buckets('month', CarbonImmutable::parse('2026-03-31 09:00:00', 'UTC'));

        // Stepping back a month at a time from the 31st lands on 3 March instead of February.
        $this->assertSame(['2026-01', '2026-02', '2026-03'], array_slice($months, -3));
        $this->assertCount(12, array_unique($months));
    }

    public function test_days_are_added_up_into_their_week_and_their_month(): void
    {
        // Sunday belongs to the week that began the Monday before, and Monday starts the next.
        $this->assertSame('2026-09-28', Insights::bucketOf('week', '2026-10-04'));
        $this->assertSame('2026-10-05', Insights::bucketOf('week', '2026-10-05'));
        $this->assertSame('2026-10', Insights::bucketOf('month', '2026-10-31'));
        $this->assertSame('2026-10-09', Insights::bucketOf('day', '2026-10-09 00:00:00'));

        $now = CarbonImmutable::parse('2026-10-09 12:00:00', 'UTC');
        $series = Insights::series('week', $now, [['2026-10-05', 2], ['2026-10-08', 3], ['2026-10-04', 1], ['2019-01-01', 99]]);

        $this->assertCount(26, $series);
        $this->assertSame(['bucket' => '2026-10-05', 'count' => 5], $series[25]);
        $this->assertSame(['bucket' => '2026-09-28', 'count' => 1], $series[24]);
        // A week with nothing in it is still on the chart, as nothing; a day outside it is left out.
        $this->assertSame(0, $series[23]['count']);
        $this->assertSame(6, array_sum(array_column($series, 'count')));
    }
}
