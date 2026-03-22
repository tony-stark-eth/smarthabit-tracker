<?php

declare(strict_types=1);

namespace Tests\Unit\Stats\Service;

use App\Stats\Service\StatsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatsService::class)]
final class StatsServiceTest extends TestCase
{
    private StatsService $service;

    protected function setUp(): void
    {
        $this->service = new StatsService();
    }

    // -------------------------------------------------------------------------
    // currentStreak
    // -------------------------------------------------------------------------

    public function testCurrentStreakThreeConsecutiveDays(): void
    {
        $today = new \DateTimeImmutable('today');
        $yesterday = new \DateTimeImmutable('yesterday');
        $twoDaysAgo = new \DateTimeImmutable('-2 days');

        $result = $this->service->currentStreak([$today, $yesterday, $twoDaysAgo]);

        self::assertSame(3, $result);
    }

    public function testCurrentStreakGapYesterdayReturnsZero(): void
    {
        $threeDaysAgo = new \DateTimeImmutable('-3 days');
        $fourDaysAgo = new \DateTimeImmutable('-4 days');

        $result = $this->service->currentStreak([$threeDaysAgo, $fourDaysAgo]);

        self::assertSame(0, $result);
    }

    public function testCurrentStreakStartedYesterdayCounts(): void
    {
        $yesterday = new \DateTimeImmutable('yesterday');
        $twoDaysAgo = new \DateTimeImmutable('-2 days');
        $threeDaysAgo = new \DateTimeImmutable('-3 days');

        $result = $this->service->currentStreak([$yesterday, $twoDaysAgo, $threeDaysAgo]);

        self::assertSame(3, $result);
    }

    public function testCurrentStreakEmptyLogsReturnsZero(): void
    {
        self::assertSame(0, $this->service->currentStreak([]));
    }

    // -------------------------------------------------------------------------
    // longestStreak
    // -------------------------------------------------------------------------

    public function testLongestStreakMultipleStreaksReturnsMax(): void
    {
        // Streak 1: Jan 1-3 (3 days)
        // Streak 2: Jan 10-15 (6 days)
        $dates = [
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-01-02'),
            new \DateTimeImmutable('2024-01-03'),
            new \DateTimeImmutable('2024-01-10'),
            new \DateTimeImmutable('2024-01-11'),
            new \DateTimeImmutable('2024-01-12'),
            new \DateTimeImmutable('2024-01-13'),
            new \DateTimeImmutable('2024-01-14'),
            new \DateTimeImmutable('2024-01-15'),
        ];

        self::assertSame(6, $this->service->longestStreak($dates));
    }

    public function testLongestStreakEmptyLogsReturnsZero(): void
    {
        self::assertSame(0, $this->service->longestStreak([]));
    }

    // -------------------------------------------------------------------------
    // completionRate
    // -------------------------------------------------------------------------

    public function testCompletionRateFifteenOfThirtyDays(): void
    {
        $dates = [];
        for ($i = 0; $i < 15; $i++) {
            $dates[] = new \DateTimeImmutable("-{$i} days");
        }

        $rate = $this->service->completionRate($dates, 30);

        self::assertEqualsWithDelta(0.5, $rate, 0.001);
    }

    public function testCompletionRateZeroDaysReturnsZero(): void
    {
        self::assertEqualsWithDelta(0.0, $this->service->completionRate([], 30), 0.001);
    }

    public function testCompletionRateZeroPeriodReturnsZero(): void
    {
        $dates = [new \DateTimeImmutable('today')];

        self::assertEqualsWithDelta(0.0, $this->service->completionRate($dates, 0), 0.001);
    }

    // -------------------------------------------------------------------------
    // averageCompletionTime
    // -------------------------------------------------------------------------

    public function testAverageCompletionTimeMedianOfThreeValues(): void
    {
        // 420 = 07:00, 430 = 07:10, 440 = 07:20
        $times = [
            new \DateTimeImmutable('07:00'),
            new \DateTimeImmutable('07:10'),
            new \DateTimeImmutable('07:20'),
        ];

        self::assertSame(430, $this->service->averageCompletionTime($times));
    }

    public function testAverageCompletionTimeEmptyReturnsNull(): void
    {
        self::assertNull($this->service->averageCompletionTime([]));
    }

    // -------------------------------------------------------------------------
    // rateTrend
    // -------------------------------------------------------------------------

    public function testRateTrendImprovingReturnsPositive(): void
    {
        $trend = $this->service->rateTrend(0.8, 0.6);

        self::assertEqualsWithDelta(0.2, $trend, 0.001);
    }

    public function testRateTrendDecliningReturnsNegative(): void
    {
        $trend = $this->service->rateTrend(0.4, 0.7);

        self::assertEqualsWithDelta(-0.3, $trend, 0.001);
    }

    // -------------------------------------------------------------------------
    // weekdayHeatmap
    // -------------------------------------------------------------------------

    public function testWeekdayHeatmapCountsPerDay(): void
    {
        // 2024-01-01 is Monday (N=1), 2024-01-03 is Wednesday (N=3)
        $dates = [
            new \DateTimeImmutable('2024-01-01'), // Mon
            new \DateTimeImmutable('2024-01-01'), // Mon (duplicate date, same weekday)
            new \DateTimeImmutable('2024-01-03'), // Wed
        ];

        /** @var array<int<1,7>, int> $heatmap */
        $heatmap = $this->service->weekdayHeatmap($dates);

        self::assertArrayHasKey(1, $heatmap);
        self::assertArrayHasKey(2, $heatmap);
        self::assertArrayHasKey(3, $heatmap);
        self::assertArrayHasKey(7, $heatmap);
        self::assertSame(2, $heatmap[1]); // Monday
        self::assertSame(0, $heatmap[2]); // Tuesday
        self::assertSame(1, $heatmap[3]); // Wednesday
        self::assertSame(0, $heatmap[7]); // Sunday
    }

    // -------------------------------------------------------------------------
    // timeHeatmap
    // -------------------------------------------------------------------------

    public function testTimeHeatmapCountsPerHour(): void
    {
        $times = [
            new \DateTimeImmutable('08:00'),
            new \DateTimeImmutable('08:30'),
            new \DateTimeImmutable('14:00'),
            new \DateTimeImmutable('22:45'),
        ];

        /** @var list<int> $heatmap */
        $heatmap = $this->service->timeHeatmap($times);

        self::assertArrayHasKey(8, $heatmap);
        self::assertArrayHasKey(14, $heatmap);
        self::assertArrayHasKey(22, $heatmap);
        self::assertArrayHasKey(0, $heatmap);
        self::assertArrayHasKey(23, $heatmap);
        self::assertSame(2, $heatmap[8]);
        self::assertSame(1, $heatmap[14]);
        self::assertSame(1, $heatmap[22]);
        self::assertSame(0, $heatmap[0]);
        self::assertSame(0, $heatmap[23]);
    }
}
