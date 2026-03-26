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
        $today = \Carbon\CarbonImmutable::today();
        $yesterday = \Carbon\CarbonImmutable::yesterday();
        $twoDaysAgo = \Carbon\CarbonImmutable::now()->subDays(2);

        $result = $this->service->currentStreak([$today, $yesterday, $twoDaysAgo]);

        self::assertSame(3, $result);
    }

    public function testCurrentStreakGapYesterdayReturnsZero(): void
    {
        $threeDaysAgo = \Carbon\CarbonImmutable::now()->subDays(3);
        $fourDaysAgo = \Carbon\CarbonImmutable::now()->subDays(4);

        $result = $this->service->currentStreak([$threeDaysAgo, $fourDaysAgo]);

        self::assertSame(0, $result);
    }

    public function testCurrentStreakStartedYesterdayCounts(): void
    {
        $yesterday = \Carbon\CarbonImmutable::yesterday();
        $twoDaysAgo = \Carbon\CarbonImmutable::now()->subDays(2);
        $threeDaysAgo = \Carbon\CarbonImmutable::now()->subDays(3);

        $result = $this->service->currentStreak([$yesterday, $twoDaysAgo, $threeDaysAgo]);

        self::assertSame(3, $result);
    }

    public function testCurrentStreakEmptyLogsReturnsZero(): void
    {
        self::assertSame(0, $this->service->currentStreak([]));
    }

    public function testCurrentStreakWithDuplicateDatesCountsOnce(): void
    {
        // Two logs for today + one for yesterday → streak=2, not affected by duplicates
        $today = \Carbon\CarbonImmutable::today();
        $yesterday = \Carbon\CarbonImmutable::yesterday();

        $result = $this->service->currentStreak([$today, $today, $yesterday]);

        self::assertSame(2, $result);
    }

    public function testCurrentStreakExactlyTwoDays(): void
    {
        // Exactly today + yesterday → streak=2 (not 3 or more from off-by-one errors)
        $today = \Carbon\CarbonImmutable::today();
        $yesterday = \Carbon\CarbonImmutable::yesterday();

        $result = $this->service->currentStreak([$today, $yesterday]);

        self::assertSame(2, $result);
    }

    // -------------------------------------------------------------------------
    // longestStreak
    // -------------------------------------------------------------------------

    public function testLongestStreakMultipleStreaksReturnsMax(): void
    {
        // Streak 1: Jan 1-3 (3 days)
        // Streak 2: Jan 10-15 (6 days)
        $dates = [
            \Carbon\CarbonImmutable::parse('2024-01-01'),
            \Carbon\CarbonImmutable::parse('2024-01-02'),
            \Carbon\CarbonImmutable::parse('2024-01-03'),
            \Carbon\CarbonImmutable::parse('2024-01-10'),
            \Carbon\CarbonImmutable::parse('2024-01-11'),
            \Carbon\CarbonImmutable::parse('2024-01-12'),
            \Carbon\CarbonImmutable::parse('2024-01-13'),
            \Carbon\CarbonImmutable::parse('2024-01-14'),
            \Carbon\CarbonImmutable::parse('2024-01-15'),
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
        $dates = [\Carbon\CarbonImmutable::today()];

        self::assertEqualsWithDelta(0.0, $this->service->completionRate($dates, 0), 0.001);
    }

    // -------------------------------------------------------------------------
    // averageCompletionTime
    // -------------------------------------------------------------------------

    public function testAverageCompletionTimeMedianOfThreeValues(): void
    {
        // 420 = 07:00, 430 = 07:10, 440 = 07:20
        $times = [
            \Carbon\CarbonImmutable::parse('07:00'),
            \Carbon\CarbonImmutable::parse('07:10'),
            \Carbon\CarbonImmutable::parse('07:20'),
        ];

        self::assertSame(430, $this->service->averageCompletionTime($times));
    }

    public function testAverageCompletionTimeEmptyReturnsNull(): void
    {
        self::assertNull($this->service->averageCompletionTime([]));
    }

    // -------------------------------------------------------------------------
    // longestStreak — additional edge cases
    // -------------------------------------------------------------------------

    public function testLongestStreakSingleDateReturnsOne(): void
    {
        $dates = [\Carbon\CarbonImmutable::parse('2024-03-15')];

        self::assertSame(1, $this->service->longestStreak($dates));
    }

    public function testLongestStreakDuplicateDatesCountedOnce(): void
    {
        // Three entries for the same day must still count as streak=1
        $dates = [
            \Carbon\CarbonImmutable::parse('2024-03-15'),
            \Carbon\CarbonImmutable::parse('2024-03-15'),
            \Carbon\CarbonImmutable::parse('2024-03-15'),
        ];

        self::assertSame(1, $this->service->longestStreak($dates));
    }

    public function testLongestStreakTwoConsecutiveDaysReturnsTwo(): void
    {
        $dates = [
            \Carbon\CarbonImmutable::parse('2024-03-15'),
            \Carbon\CarbonImmutable::parse('2024-03-16'),
        ];

        self::assertSame(2, $this->service->longestStreak($dates));
    }

    public function testLongestStreakFirstStreakIsLonger(): void
    {
        // Streak 1: Jan 10-15 (6 days), Streak 2: Jan 20-21 (2 days)
        $dates = [
            \Carbon\CarbonImmutable::parse('2024-01-10'),
            \Carbon\CarbonImmutable::parse('2024-01-11'),
            \Carbon\CarbonImmutable::parse('2024-01-12'),
            \Carbon\CarbonImmutable::parse('2024-01-13'),
            \Carbon\CarbonImmutable::parse('2024-01-14'),
            \Carbon\CarbonImmutable::parse('2024-01-15'),
            \Carbon\CarbonImmutable::parse('2024-01-20'),
            \Carbon\CarbonImmutable::parse('2024-01-21'),
        ];

        self::assertSame(6, $this->service->longestStreak($dates));
    }

    public function testLongestStreakDuplicateInMiddleOfStreakCountsOnce(): void
    {
        // Jan1, Jan2 (duplicate), Jan2, Jan3 → unique: [Jan1, Jan2, Jan3] → streak=3
        // Without array_unique: [Jan1, Jan2, Jan2, Jan3] → diff(Jan2, Jan2)=0 → streak resets → longest=2
        $dates = [
            \Carbon\CarbonImmutable::parse('2024-01-01'),
            \Carbon\CarbonImmutable::parse('2024-01-02'),
            \Carbon\CarbonImmutable::parse('2024-01-02'), // duplicate
            \Carbon\CarbonImmutable::parse('2024-01-03'),
        ];

        self::assertSame(3, $this->service->longestStreak($dates));
    }

    public function testLongestStreakWithUnsortedInputSortsByDate(): void
    {
        // Dates given in scrambled order — sort() must fix this
        // Without sort: [Jan3, Jan1, Jan2] → diff(Jan3,Jan1)=2 reset, diff(Jan1,Jan2)=1 → longest=2
        // With sort: [Jan1, Jan2, Jan3] → consecutive streak=3
        $dates = [
            \Carbon\CarbonImmutable::parse('2024-01-03'),
            \Carbon\CarbonImmutable::parse('2024-01-01'),
            \Carbon\CarbonImmutable::parse('2024-01-02'),
        ];

        self::assertSame(3, $this->service->longestStreak($dates));
    }

    // -------------------------------------------------------------------------
    // completionRate — additional edge cases
    // -------------------------------------------------------------------------

    public function testCompletionRateDuplicateDaysCountedOnce(): void
    {
        // 3 entries for 1 unique day out of 10 = 0.1
        $dates = [
            \Carbon\CarbonImmutable::parse('2024-01-01'),
            \Carbon\CarbonImmutable::parse('2024-01-01'),
            \Carbon\CarbonImmutable::parse('2024-01-01'),
        ];

        self::assertEqualsWithDelta(0.1, $this->service->completionRate($dates, 10), 0.001);
    }

    public function testCompletionRateCapsAtOne(): void
    {
        // More unique days than period days must cap at 1.0
        $dates = [
            \Carbon\CarbonImmutable::parse('2024-01-01'),
            \Carbon\CarbonImmutable::parse('2024-01-02'),
            \Carbon\CarbonImmutable::parse('2024-01-03'),
        ];

        self::assertEqualsWithDelta(1.0, $this->service->completionRate($dates, 2), 0.001);
    }

    // -------------------------------------------------------------------------
    // averageCompletionTime — additional edge cases
    // -------------------------------------------------------------------------

    public function testAverageCompletionTimeSingleEntryReturnsThatValue(): void
    {
        // 09:30 = 9*60 + 30 = 570
        $times = [\Carbon\CarbonImmutable::parse('09:30')];

        self::assertSame(570, $this->service->averageCompletionTime($times));
    }

    public function testAverageCompletionTimeEvenCountUsesAverageOfMiddleTwo(): void
    {
        // 08:00 = 480, 08:10 = 490, 08:20 = 500, 08:30 = 510
        // Sorted: [480, 490, 500, 510] count=4, mid=2
        // Even path: (minutes[1] + minutes[2]) / 2 = (490 + 500) / 2 = 495
        $times = [
            \Carbon\CarbonImmutable::parse('08:00'),
            \Carbon\CarbonImmutable::parse('08:10'),
            \Carbon\CarbonImmutable::parse('08:20'),
            \Carbon\CarbonImmutable::parse('08:30'),
        ];

        self::assertSame(495, $this->service->averageCompletionTime($times));
    }

    public function testAverageCompletionTimeTwoEntriesUsesEvenPath(): void
    {
        // 08:00 = 480, 10:00 = 600; even: (480 + 600) / 2 = 540
        $times = [
            \Carbon\CarbonImmutable::parse('08:00'),
            \Carbon\CarbonImmutable::parse('10:00'),
        ];

        self::assertSame(540, $this->service->averageCompletionTime($times));
    }

    public function testAverageCompletionTimeMinutesPartIsUsed(): void
    {
        // 07:45 = 7*60+45 = 465; ensure (int)format('i') is included correctly
        $times = [\Carbon\CarbonImmutable::parse('07:45')];

        self::assertSame(465, $this->service->averageCompletionTime($times));
    }

    public function testAverageCompletionTimeHourContributesCorrectly(): void
    {
        // Midnight: 00:00 = 0; 01:00 = 60; ensure (int)format('G') scales by 60
        $times = [\Carbon\CarbonImmutable::parse('01:00')];

        self::assertSame(60, $this->service->averageCompletionTime($times));
    }

    public function testAverageCompletionTimeEvenCountRoundsHalfUp(): void
    {
        // 08:00 = 480, 08:21 = 501 (odd sum: 480+501=981, /2=490.5)
        // round(490.5) = 491, floor(490.5) = 490 → distinguishes round from floor
        $times = [
            \Carbon\CarbonImmutable::parse('08:00'),
            \Carbon\CarbonImmutable::parse('08:21'),
        ];

        self::assertSame(491, $this->service->averageCompletionTime($times));
    }

    public function testAverageCompletionTimeUnsortedInputGivesCorrectMedian(): void
    {
        // Without sort, [09:00, 08:00, 08:30] = [540, 480, 510], mid=1, returns 480 (wrong)
        // With sort, [480, 510, 540], mid=1, returns 510 (correct)
        $times = [
            \Carbon\CarbonImmutable::parse('09:00'),
            \Carbon\CarbonImmutable::parse('08:00'),
            \Carbon\CarbonImmutable::parse('08:30'),
        ];

        self::assertSame(510, $this->service->averageCompletionTime($times));
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

    public function testRateTrendRoundsToTwoDecimalPlaces(): void
    {
        // 0.8 - 0.575 = 0.225 → rounded to 2dp = 0.23, not 0.2 (1dp) or 0.225 (3dp)
        $trend = $this->service->rateTrend(0.8, 0.575);

        self::assertSame(0.23, $trend);
    }

    public function testRateTrendZeroWhenRatesAreEqual(): void
    {
        self::assertEqualsWithDelta(0.0, $this->service->rateTrend(0.5, 0.5), 0.001);
    }

    // -------------------------------------------------------------------------
    // weekdayHeatmap
    // -------------------------------------------------------------------------

    public function testWeekdayHeatmapCountsPerDay(): void
    {
        // 2024-01-01 is Monday (N=1), 2024-01-03 is Wednesday (N=3)
        $dates = [
            \Carbon\CarbonImmutable::parse('2024-01-01'), // Mon
            \Carbon\CarbonImmutable::parse('2024-01-01'), // Mon (duplicate date, same weekday)
            \Carbon\CarbonImmutable::parse('2024-01-03'), // Wed
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

    public function testWeekdayHeatmapContainsExactlySevenKeys(): void
    {
        $heatmap = $this->service->weekdayHeatmap([]);

        self::assertCount(7, $heatmap);
        for ($day = 1; $day <= 7; $day++) {
            self::assertArrayHasKey($day, $heatmap);
            self::assertSame(0, $heatmap[$day]);
        }
    }

    public function testWeekdayHeatmapAllSevenDays(): void
    {
        // 2024-01-01 (Mon) through 2024-01-07 (Sun) covers all weekdays
        $dates = [];
        for ($i = 0; $i < 7; $i++) {
            $dates[] = new \DateTimeImmutable('2024-01-0' . ($i + 1));
        }

        $heatmap = $this->service->weekdayHeatmap($dates);

        for ($day = 1; $day <= 7; $day++) {
            self::assertArrayHasKey($day, $heatmap);
            self::assertSame(1, $heatmap[$day], "Day {$day} should have count 1");
        }
    }

    // -------------------------------------------------------------------------
    // timeHeatmap
    // -------------------------------------------------------------------------

    public function testTimeHeatmapCountsPerHour(): void
    {
        $times = [
            \Carbon\CarbonImmutable::parse('08:00'),
            \Carbon\CarbonImmutable::parse('08:30'),
            \Carbon\CarbonImmutable::parse('14:00'),
            \Carbon\CarbonImmutable::parse('22:45'),
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

    public function testTimeHeatmapContainsExactlyTwentyFourKeys(): void
    {
        $heatmap = $this->service->timeHeatmap([]);

        self::assertCount(24, $heatmap);
        for ($hour = 0; $hour <= 23; $hour++) {
            self::assertArrayHasKey($hour, $heatmap);
            self::assertSame(0, $heatmap[$hour]);
        }
    }

    public function testTimeHeatmapMidnightAndEndOfDay(): void
    {
        $times = [
            \Carbon\CarbonImmutable::parse('00:00'),
            \Carbon\CarbonImmutable::parse('23:59'),
        ];

        $heatmap = $this->service->timeHeatmap($times);

        self::assertArrayHasKey(0, $heatmap);
        self::assertArrayHasKey(23, $heatmap);
        self::assertSame(1, $heatmap[0]);
        self::assertSame(1, $heatmap[23]);
    }
}
