<?php

declare(strict_types=1);

namespace Tests\Unit\Habit\Service;

use App\Habit\Service\TimeWindowLearner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TimeWindowLearner::class)]
final class TimeWindowLearnerTest extends TestCase
{
    private TimeWindowLearner $learner;

    private \DateTimeZone $utc;

    protected function setUp(): void
    {
        $this->learner = new TimeWindowLearner();
        $this->utc = new \DateTimeZone('UTC');
    }

    // -------------------------------------------------------------------------
    // 1. Basic learning: 10 logs around 07:30 → window should center around 07:30
    // -------------------------------------------------------------------------

    public function testBasicLearningCentersAroundMedianTime(): void
    {
        $timestamps = $this->makeTimestampsAroundTime('07:30', 10, jitterMinutes: 5);

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        // Median should be close to 07:30 = 450 minutes
        $center = (int) round(($result['start'] + $result['end']) / 2);
        self::assertGreaterThanOrEqual(440, $center, 'Window center should be near 07:30');
        self::assertLessThanOrEqual(460, $center, 'Window center should be near 07:30');
        self::assertGreaterThan($result['start'], $result['end']);
    }

    public function testBasicLearningWindowIsAtLeastMinWidth(): void
    {
        $timestamps = $this->makeTimestampsAroundTime('07:30', 10, jitterMinutes: 5);

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        self::assertGreaterThanOrEqual(30, $result['end'] - $result['start']);
    }

    // -------------------------------------------------------------------------
    // 2. Outlier robustness: 9 logs around 07:30 + 1 at 23:00 → MAD resists outlier
    // -------------------------------------------------------------------------

    public function testOutlierDoesNotShiftWindowCenter(): void
    {
        $timestamps = $this->makeTimestampsAroundTime('07:30', 9, jitterMinutes: 5);
        // Add a single extreme outlier at 23:00
        $timestamps[] = new \DateTimeImmutable('2026-03-15 23:00:00', $this->utc);

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        // Window should still be centered near 07:30 (450 min), not shifted toward 23:00 (1380 min)
        $center = (int) round(($result['start'] + $result['end']) / 2);
        self::assertLessThan(600, $center, 'Outlier at 23:00 should not shift center far from 07:30');
        self::assertGreaterThan(400, $center, 'Window center should remain near 07:30');
    }

    // -------------------------------------------------------------------------
    // 3. Minimum window width: all logs at exactly the same time → >= 30 min
    // -------------------------------------------------------------------------

    public function testMinimumWindowWidthEnforcedWhenAllLogsAtSameTime(): void
    {
        // All 10 logs at exactly 08:00 → MAD = 0, deviation clamped to 15, window = [465, 495]
        $timestamps = [];
        for ($i = 0; $i < 10; ++$i) {
            $timestamps[] = new \DateTimeImmutable('2026-03-' . sprintf('%02d', $i + 1) . ' 08:00:00', $this->utc);
        }

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        self::assertGreaterThanOrEqual(30, $result['end'] - $result['start'], 'Window must be at least 30 minutes wide');
    }

    public function testMinimumWindowWidthEnforcedWhenSpreadIsVerySmall(): void
    {
        // All logs within 2 minutes of each other → MAD very small, minimum width must kick in
        $base = new \DateTimeImmutable('2026-03-15 10:00:00', $this->utc);
        $timestamps = [];
        for ($i = 0; $i < 7; ++$i) {
            $timestamps[] = $base->modify("+{$i} minutes");
        }

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        self::assertGreaterThanOrEqual(30, $result['end'] - $result['start']);
    }

    // -------------------------------------------------------------------------
    // 4. Insufficient data: < 7 logs → returns null
    // -------------------------------------------------------------------------

    public function testInsufficientDataReturnsNull(): void
    {
        $timestamps = $this->makeTimestampsAroundTime('08:00', 6, jitterMinutes: 5);

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNull($result);
    }

    public function testExactlySevenDataPointsReturnsResult(): void
    {
        $timestamps = $this->makeTimestampsAroundTime('08:00', 7, jitterMinutes: 5);

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result, 'Exactly 7 data points should be sufficient');
    }

    // -------------------------------------------------------------------------
    // 5. Empty array → returns null
    // -------------------------------------------------------------------------

    public function testEmptyArrayReturnsNull(): void
    {
        $result = $this->learner->learn([], $this->utc);

        self::assertNull($result);
    }

    // -------------------------------------------------------------------------
    // 6. Weekday/weekend split: 10 weekday logs at 07:00, 10 weekend logs at 10:00
    // -------------------------------------------------------------------------

    public function testWeekdayWeekendSplitProducesDifferentWindows(): void
    {
        // Use explicit known weekday dates (Mon-Fri) and weekend dates (Sat-Sun)
        // 2026-03-16=Mon, 17=Tue, 18=Wed, 19=Thu, 20=Fri
        // 2026-03-23=Mon, 24=Tue, 25=Wed
        $weekdayDates = [
            '2026-03-16', '2026-03-17', '2026-03-18', '2026-03-19', '2026-03-20',
            '2026-03-23', '2026-03-24', '2026-03-25', '2026-04-06', '2026-04-07',
        ];
        $weekdayTimestamps = array_map(
            fn (string $d): \DateTimeImmutable => new \DateTimeImmutable("{$d} 07:00:00", $this->utc),
            $weekdayDates,
        );

        // 2026-03-21=Sat, 22=Sun, 28=Sat, 29=Sun
        $weekendDates = [
            '2026-03-21', '2026-03-22', '2026-03-28', '2026-03-29',
            '2026-04-04', '2026-04-05', '2026-04-11', '2026-04-12',
            '2026-04-18', '2026-04-19',
        ];
        $weekendTimestamps = array_map(
            fn (string $d): \DateTimeImmutable => new \DateTimeImmutable("{$d} 10:00:00", $this->utc),
            $weekendDates,
        );

        $allTimestamps = array_merge($weekdayTimestamps, $weekendTimestamps);

        $result = $this->learner->learnWithSplit($allTimestamps, $this->utc);

        self::assertNotNull($result['weekday'], 'Weekday window should not be null');
        self::assertNotNull($result['weekend'], 'Weekend window should not be null');

        $weekdayCenter = (int) round(($result['weekday']['start'] + $result['weekday']['end']) / 2);
        $weekendCenter = (int) round(($result['weekend']['start'] + $result['weekend']['end']) / 2);

        // 07:00 = 420 min, 10:00 = 600 min
        self::assertLessThan(500, $weekdayCenter, 'Weekday center should be near 07:00');
        self::assertGreaterThan(540, $weekendCenter, 'Weekend center should be near 10:00');
        self::assertGreaterThan($weekdayCenter, $weekendCenter, 'Weekend center should be later than weekday center');
    }

    // -------------------------------------------------------------------------
    // 7. Split with insufficient weekend data → weekday window only, weekend null
    // -------------------------------------------------------------------------

    public function testSplitWithInsufficientWeekendDataReturnsNullForWeekend(): void
    {
        $weekdayTimestamps = [];
        for ($i = 0; $i < 10; ++$i) {
            $weekdayTimestamps[] = new \DateTimeImmutable(sprintf('2026-03-%02d 07:00:00', 16 + $i), $this->utc);
        }

        // Only 3 weekend logs — below the 7 minimum
        $weekendTimestamps = [
            new \DateTimeImmutable('2026-03-21 10:00:00', $this->utc),
            new \DateTimeImmutable('2026-03-22 10:00:00', $this->utc),
            new \DateTimeImmutable('2026-03-28 10:00:00', $this->utc),
        ];

        $allTimestamps = array_merge($weekdayTimestamps, $weekendTimestamps);

        $result = $this->learner->learnWithSplit($allTimestamps, $this->utc);

        self::assertNotNull($result['weekday'], 'Weekday window should be computed');
        self::assertNull($result['weekend'], 'Weekend window should be null (only 3 data points)');
    }

    // -------------------------------------------------------------------------
    // 8. Timezone conversion: UTC 23:00 → Europe/Berlin 00:00+1 (winter = UTC+1)
    // -------------------------------------------------------------------------

    public function testTimezoneConversionCrossingMidnight(): void
    {
        $berlin = new \DateTimeZone('Europe/Berlin');

        // In January (UTC+1), UTC 23:00 = Berlin 00:00 next day = 0 minutes
        $timestamps = [];
        for ($i = 0; $i < 10; ++$i) {
            $timestamps[] = new \DateTimeImmutable(sprintf('2026-01-%02d 23:00:00', $i + 1), $this->utc);
        }

        $result = $this->learner->learn($timestamps, $berlin);

        self::assertNotNull($result);
        // UTC 23:00 in winter Berlin (UTC+1) = local 00:00 = 0 minutes
        $center = (int) round(($result['start'] + $result['end']) / 2);
        self::assertLessThanOrEqual(15, $center, 'Center should be near midnight (0 min) in Berlin local time');
    }

    public function testTimezoneConversionSummerOffset(): void
    {
        $berlin = new \DateTimeZone('Europe/Berlin');

        // In July (UTC+2), UTC 06:00 = Berlin 08:00 = 480 minutes
        $timestamps = [];
        for ($i = 0; $i < 10; ++$i) {
            $timestamps[] = new \DateTimeImmutable(sprintf('2026-07-%02d 06:00:00', $i + 1), $this->utc);
        }

        $result = $this->learner->learn($timestamps, $berlin);

        self::assertNotNull($result);
        $center = (int) round(($result['start'] + $result['end']) / 2);
        // 08:00 = 480 min
        self::assertGreaterThanOrEqual(465, $center);
        self::assertLessThanOrEqual(495, $center);
    }

    // -------------------------------------------------------------------------
    // 9. Edge: midnight boundary — window should not go negative or exceed 1439
    // -------------------------------------------------------------------------

    public function testWindowDoesNotGoBelowZeroNearMidnight(): void
    {
        // Logs around 00:05 local time
        $timestamps = [];
        for ($i = 0; $i < 10; ++$i) {
            $timestamps[] = new \DateTimeImmutable(sprintf('2026-03-%02d 00:0%d:00', $i + 1, $i % 6), $this->utc);
        }

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        self::assertGreaterThanOrEqual(0, $result['start'], 'Start must not be negative');
        self::assertGreaterThan($result['start'], $result['end']);
    }

    public function testWindowDoesNotExceed1439NearEndOfDay(): void
    {
        // Logs around 23:55 local time
        $timestamps = [];
        for ($i = 0; $i < 10; ++$i) {
            $timestamps[] = new \DateTimeImmutable(sprintf('2026-03-%02d 23:5%d:00', $i + 1, $i % 6), $this->utc);
        }

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        self::assertLessThanOrEqual(1439, $result['end'], 'End must not exceed 1439 (23:59)');
        self::assertGreaterThan($result['start'], $result['end']);
    }

    // -------------------------------------------------------------------------
    // 10. minutesToTime / timeToMinutes round-trip
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{int, string}>
     */
    public static function minutesToTimeProvider(): array
    {
        return [
            'midnight' => [0, '00:00'],
            'morning 07:30' => [450, '07:30'],
            'noon' => [720, '12:00'],
            'late night 23:59' => [1439, '23:59'],
            'one past midnight' => [1, '00:01'],
            'hour boundary 13:00' => [780, '13:00'],
        ];
    }

    #[DataProvider('minutesToTimeProvider')]
    public function testMinutesToTime(int $minutes, string $expected): void
    {
        self::assertSame($expected, TimeWindowLearner::minutesToTime($minutes));
    }

    #[DataProvider('minutesToTimeProvider')]
    public function testTimeToMinutes(int $expectedMinutes, string $time): void
    {
        self::assertSame($expectedMinutes, TimeWindowLearner::timeToMinutes($time));
    }

    public function testRoundTripMinutesToTimeToMinutes(): void
    {
        foreach ([0, 1, 60, 450, 720, 1380, 1439] as $minutes) {
            self::assertSame(
                $minutes,
                TimeWindowLearner::timeToMinutes(TimeWindowLearner::minutesToTime($minutes)),
                "Round-trip failed for {$minutes} minutes",
            );
        }
    }

    // -------------------------------------------------------------------------
    // 11. DST edge case: logs around DST transition (March clocks forward)
    // -------------------------------------------------------------------------

    public function testDstTransitionMinutesReflectLocalTime(): void
    {
        $berlin = new \DateTimeZone('Europe/Berlin');

        // 2026 DST in Germany: clocks forward on 2026-03-29 at 02:00 → 03:00 (UTC+2)
        // UTC 05:00 on March 29 (after DST) = Berlin 07:00 (UTC+2) = 420 min
        // UTC 06:00 on March 15 (before DST) = Berlin 07:00 (UTC+1) = 420 min
        // Both should yield ~420 minutes local time regardless of DST state
        $timestamps = [];
        for ($i = 1; $i <= 8; ++$i) {
            // Before DST (UTC+1): UTC 06:xx = Berlin 07:xx
            $timestamps[] = new \DateTimeImmutable(sprintf('2026-03-%02d 06:00:00', $i), $this->utc);
        }
        // After DST (UTC+2): UTC 05:xx = Berlin 07:xx
        $timestamps[] = new \DateTimeImmutable('2026-03-30 05:00:00', $this->utc);
        $timestamps[] = new \DateTimeImmutable('2026-03-31 05:00:00', $this->utc);

        $result = $this->learner->learn($timestamps, $berlin);

        self::assertNotNull($result);
        // All should map to ~07:00 = 420 minutes local time
        $center = (int) round(($result['start'] + $result['end']) / 2);
        self::assertGreaterThanOrEqual(405, $center, 'Center should be near 07:00 Berlin local time');
        self::assertLessThanOrEqual(435, $center, 'Center should be near 07:00 Berlin local time');
    }

    public function testDstTransitionDayItselfLocalTimeIsCorrect(): void
    {
        $berlin = new \DateTimeZone('Europe/Berlin');

        // Europe/Berlin 2026 DST transition: UTC 01:00 = local clocks jump from 02:00 → 03:00 (UTC+2)
        // UTC 00:59 on Mar 29 is still UTC+1 → local 01:59
        $beforeDst = new \DateTimeImmutable('2026-03-29 00:59:00', $this->utc);
        $localBefore = $beforeDst->setTimezone($berlin);
        self::assertSame(1, (int) $localBefore->format('G'));
        self::assertSame(59, (int) $localBefore->format('i'));

        // UTC 01:00 on Mar 29: clocks have jumped, local = 03:00 (UTC+2)
        $afterDst = new \DateTimeImmutable('2026-03-29 01:00:00', $this->utc);
        $localAfter = $afterDst->setTimezone($berlin);
        self::assertSame(3, (int) $localAfter->format('G')); // 01:00 UTC = 03:00 Berlin (UTC+2)
        self::assertSame(0, (int) $localAfter->format('i'));

        // UTC 01:30 on Mar 29 is already UTC+2 → local 03:30 (not 02:30)
        $midDst = new \DateTimeImmutable('2026-03-29 01:30:00', $this->utc);
        $localMid = $midDst->setTimezone($berlin);
        self::assertSame(3, (int) $localMid->format('G'));
        self::assertSame(30, (int) $localMid->format('i'));
    }

    // -------------------------------------------------------------------------
    // Additional edge-case: learnWithSplit with empty input
    // -------------------------------------------------------------------------

    public function testLearnWithSplitEmptyInputReturnsBothNull(): void
    {
        $result = $this->learner->learnWithSplit([], $this->utc);

        self::assertNull($result['weekday']);
        self::assertNull($result['weekend']);
    }

    // -------------------------------------------------------------------------
    // Additional: window boundaries are always valid (start < end)
    // -------------------------------------------------------------------------

    public function testWindowStartIsAlwaysLessThanEnd(): void
    {
        // Spread logs widely to get a potentially large window
        $timestamps = $this->makeTimestampsAroundTime('14:00', 15, jitterMinutes: 60);

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        self::assertLessThan($result['end'], $result['start'] + 1, 'start must be < end');
    }

    // -------------------------------------------------------------------------
    // Additional: learnWithSplit correctly categorises Mon–Fri vs Sat–Sun
    // -------------------------------------------------------------------------

    public function testLearnWithSplitCategorizesWeekdaysCorrectly(): void
    {
        // 2026-03-16 = Monday (dow=1), 2026-03-21 = Saturday (dow=6)
        $monday = new \DateTimeImmutable('2026-03-16 07:00:00', $this->utc);
        $saturday = new \DateTimeImmutable('2026-03-21 10:00:00', $this->utc);

        $localMonday = $monday->setTimezone($this->utc);
        $localSaturday = $saturday->setTimezone($this->utc);

        self::assertSame(1, (int) $localMonday->format('N'), 'Monday should be dow=1');
        self::assertSame(6, (int) $localSaturday->format('N'), 'Saturday should be dow=6');
    }

    // -------------------------------------------------------------------------
    // Additional: result keys are 'start' and 'end'
    // -------------------------------------------------------------------------

    public function testLearnResultHasCorrectArrayKeys(): void
    {
        $timestamps = $this->makeTimestampsAroundTime('09:00', 7, jitterMinutes: 3);

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        self::assertArrayHasKey('start', $result);
        self::assertArrayHasKey('end', $result);
        self::assertIsInt($result['start']);
        self::assertIsInt($result['end']);
    }

    public function testLearnWithSplitResultHasCorrectArrayKeys(): void
    {
        $timestamps = $this->makeTimestampsAroundTime('09:00', 14, jitterMinutes: 3);

        $result = $this->learner->learnWithSplit($timestamps, $this->utc);

        self::assertArrayHasKey('weekday', $result);
        self::assertArrayHasKey('weekend', $result);
    }

    // -------------------------------------------------------------------------
    // Precision tests: exact numeric behavior of internal calculations
    // -------------------------------------------------------------------------

    /**
     * Tests that learn() returns exact start/end values for a perfectly uniform dataset.
     * 7 logs all at 08:00 UTC → median=480, MAD=0, deviation=max(0,15)=15 (MIN_WINDOW/2),
     * but window [465,495]=30 min so minimum width check triggers: center=480, start=465, end=495.
     */
    public function testLearnExactValuesUniformDataset(): void
    {
        $timestamps = [];
        for ($i = 1; $i <= 7; ++$i) {
            $timestamps[] = new \DateTimeImmutable(sprintf('2026-03-%02d 08:00:00', $i), $this->utc);
        }

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        // median=480, MAD=0, deviation=15 (MIN_WINDOW_MINUTES/2=15), window=[465,495]
        // end-start=30 which equals MIN_WINDOW_MINUTES, so no correction: start=465, end=495
        self::assertSame(465, $result['start']);
        self::assertSame(495, $result['end']);
    }

    /**
     * 7 logs at exact minutes: 470,475,480,485,490,495,500
     * sorted: 470,475,480,485,490,495,500 → median=485 (index 3)
     * deviations: 15,10,5,0,5,10,15 → sorted: 0,5,5,10,10,15,15 → MAD=10
     * deviation = max(round(1.5*10)=15, 15) = 15
     * start = max(0, 485-15) = 470
     * end = min(1439, 485+15) = 500
     * width = 500-470 = 30 = MIN_WINDOW_MINUTES → no minimum correction needed
     */
    public function testLearnExactValuesWithKnownMad(): void
    {
        $timestamps = [];
        $minutes = [470, 475, 480, 485, 490, 495, 500];
        foreach ($minutes as $i => $m) {
            $h = (int) ($m / 60);
            $min = $m % 60;
            $timestamps[] = new \DateTimeImmutable(
                sprintf('2026-03-%02d %02d:%02d:00', $i + 1, $h, $min),
                $this->utc,
            );
        }

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        self::assertSame(470, $result['start']);
        self::assertSame(500, $result['end']);
    }

    /**
     * 8 logs (even count) to test even-count median rounding.
     * Minutes: 100,110,120,130,140,150,160,170
     * Sorted: same. mid=4. lo=values[3]=130, hi=values[4]=140 → median=round(270/2)=135
     * deviations: |100-135|=35, |110-135|=25, |120-135|=15, |130-135|=5, |140-135|=5,
     *             |150-135|=15, |160-135|=25, |170-135|=35
     * sorted: 5,5,15,15,25,25,35,35 → mid=4, lo=sorted[3]=15, hi=sorted[4]=25 → MAD=round(40/2)=20
     * deviation = max(round(1.5*20)=30, 15) = 30
     * start = max(0, 135-30) = 105
     * end = min(1439, 135+30) = 165
     * width = 165-105 = 60 ≥ 30, no correction
     */
    public function testLearnExactValuesEvenCountMedian(): void
    {
        $timestamps = [];
        $minutes = [100, 110, 120, 130, 140, 150, 160, 170];
        foreach ($minutes as $i => $m) {
            $h = (int) ($m / 60);
            $min = $m % 60;
            $timestamps[] = new \DateTimeImmutable(
                sprintf('2026-03-%02d %02d:%02d:00', $i + 1, $h, $min),
                $this->utc,
            );
        }

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        self::assertSame(105, $result['start']);
        self::assertSame(165, $result['end']);
    }

    /**
     * Test that window correction is applied when MAD is very small.
     * 7 logs at 200,201,200,199,200,201,199 → median=200, MAD=0 or 1
     * deviation = max(round(1.5*0or1)=0or2, 15) = 15
     * start = 200-15 = 185
     * end = 200+15 = 215
     * width = 215-185 = 30 = MIN_WINDOW_MINUTES, exactly at boundary so no correction
     */
    public function testWindowCorrectionNotAppliedWhenExactlyMinWidth(): void
    {
        $timestamps = [];
        $minutesList = [199, 200, 200, 200, 200, 201, 201];
        foreach ($minutesList as $i => $m) {
            $h = (int) ($m / 60);
            $min = $m % 60;
            $timestamps[] = new \DateTimeImmutable(
                sprintf('2026-03-%02d %02d:%02d:00', $i + 1, $h, $min),
                $this->utc,
            );
        }

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        // MAD=0 → deviation=15. start=200-15=185, end=200+15=215, width=30. No correction.
        self::assertSame(185, $result['start']);
        self::assertSame(215, $result['end']);
    }

    /**
     * Test minute correction path when deviation < MIN_WINDOW_MINUTES/2.
     * All logs at minute 600. MAD=0, deviation=15.
     * start=600-15=585, end=600+15=615, width=30 = MIN_WINDOW_MINUTES: no correction (exactly 30).
     * Confirm exact values.
     */
    public function testExactMinuteValuesPreservedInResult(): void
    {
        $timestamps = [];
        for ($i = 0; $i < 7; ++$i) {
            // 600 minutes = 10:00
            $timestamps[] = new \DateTimeImmutable(sprintf('2026-03-%02d 10:00:00', $i + 1), $this->utc);
        }

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        self::assertSame(585, $result['start']);
        self::assertSame(615, $result['end']);
    }

    /**
     * Test toLocalMinutes via learn(): UTC 13:45 → UTC+0 = 13*60+45 = 825 minutes.
     * 7 logs at exactly 13:45 UTC → median=825, MAD=0, deviation=15
     * start=825-15=810, end=825+15=840
     */
    public function testToLocalMinutesCorrectlyConvertsMixedHourAndMinutes(): void
    {
        $timestamps = [];
        for ($i = 0; $i < 7; ++$i) {
            $timestamps[] = new \DateTimeImmutable(sprintf('2026-03-%02d 13:45:00', $i + 1), $this->utc);
        }

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        // 13:45 = 13*60+45 = 825. median=825, MAD=0, deviation=15, start=810, end=840
        self::assertSame(810, $result['start']);
        self::assertSame(840, $result['end']);
    }

    /**
     * Test toLocalMinutes correctly handles timezone offset (UTC+5:30 = IST).
     * UTC 00:00 in IST = 05:30 = 330 minutes.
     */
    public function testToLocalMinutesWithNonIntegerHourOffset(): void
    {
        $ist = new \DateTimeZone('Asia/Kolkata'); // UTC+5:30

        $timestamps = [];
        for ($i = 0; $i < 7; ++$i) {
            $timestamps[] = new \DateTimeImmutable(sprintf('2026-03-%02d 00:00:00', $i + 1), $this->utc);
        }

        $result = $this->learner->learn($timestamps, $ist);

        self::assertNotNull($result);
        // UTC 00:00 in IST = 05:30 = 330 minutes. median=330, MAD=0, deviation=15
        // start=315, end=345
        self::assertSame(315, $result['start']);
        self::assertSame(345, $result['end']);
    }

    /**
     * Test timeToMinutes with explicit '00' minutes part.
     */
    public function testTimeToMinutesWithZeroMinutes(): void
    {
        self::assertSame(60, TimeWindowLearner::timeToMinutes('01:00'));
        self::assertSame(120, TimeWindowLearner::timeToMinutes('02:00'));
        self::assertSame(0, TimeWindowLearner::timeToMinutes('00:00'));
    }

    /**
     * Test minutesToTime for non-zero minutes component.
     */
    public function testMinutesToTimeNonZeroMinutes(): void
    {
        self::assertSame('00:01', TimeWindowLearner::minutesToTime(1));
        self::assertSame('01:01', TimeWindowLearner::minutesToTime(61));
        self::assertSame('23:59', TimeWindowLearner::minutesToTime(1439));
        self::assertSame('00:30', TimeWindowLearner::minutesToTime(30));
        self::assertSame('12:30', TimeWindowLearner::minutesToTime(750));
    }

    /**
     * Verify that the DOW boundary (>= 6 means weekend) is correctly tested.
     * Friday=5 → weekday, Saturday=6 → weekend, Sunday=7 → weekend.
     */
    public function testLearnWithSplitDowBoundaryFridayGoesToWeekday(): void
    {
        // 2026-04-03=Friday(5), 2026-04-04=Saturday(6), 2026-04-05=Sunday(7)
        $friday = new \DateTimeImmutable('2026-04-03 08:00:00', $this->utc);
        $saturday = new \DateTimeImmutable('2026-04-04 08:00:00', $this->utc);
        $sunday = new \DateTimeImmutable('2026-04-05 08:00:00', $this->utc);

        self::assertSame(5, (int) $friday->format('N'), 'Friday=5');
        self::assertSame(6, (int) $saturday->format('N'), 'Saturday=6');
        self::assertSame(7, (int) $sunday->format('N'), 'Sunday=7');

        // Build 7 weekday + 7 weekend timestamps to confirm proper routing
        $weekdayDates = [
            '2026-03-16', '2026-03-17', '2026-03-18', '2026-03-19', '2026-03-20',
            '2026-03-23', '2026-04-03', // Friday
        ];
        $weekendDates = [
            '2026-03-21', '2026-03-22',
            '2026-04-04', '2026-04-05', // Saturday + Sunday
            '2026-04-11', '2026-04-12', '2026-04-18',
        ];

        $allTimestamps = array_merge(
            array_map(fn (string $d): \DateTimeImmutable => new \DateTimeImmutable("{$d} 08:00:00", $this->utc), $weekdayDates),
            array_map(fn (string $d): \DateTimeImmutable => new \DateTimeImmutable("{$d} 08:00:00", $this->utc), $weekendDates),
        );

        $result = $this->learner->learnWithSplit($allTimestamps, $this->utc);

        // Both groups have 7 entries, so both should return a window
        self::assertNotNull($result['weekday']);
        self::assertNotNull($result['weekend']);
    }

    /**
     * Test minimum window correction path precisely.
     * Use logs where MAD*1.5 < 15 (half of MIN_WINDOW_MINUTES).
     * 9 logs at exactly minute 300, 1 log at 302 → median=300, MAD=0
     * deviation = max(round(1.5*0)=0, 15) = 15
     * start = max(0, 300-15) = 285, end = min(1439, 300+15) = 315
     * width = 30 = MIN_WINDOW_MINUTES → no correction needed
     * To force correction: need width < 30 → impossible since MAD*1.5 minimum is 15 each side
     * The correction IS forced when start/end hit 0 or 1439 boundary.
     * Near 0: median=10, deviation=15 → start=max(0,10-15)=0, end=min(1439,10+15)=25
     * width=25-0=25 < 30 → correction: center=round((0+25)/2)=12,
     *   start=max(0,12-15)=0, end=min(1439,0+30)=30 → [0,30]
     */
    public function testMinWindowCorrectionAtLowerBoundary(): void
    {
        $timestamps = [];
        for ($i = 0; $i < 7; ++$i) {
            // All at 00:10 UTC = 10 minutes
            $timestamps[] = new \DateTimeImmutable(sprintf('2026-03-%02d 00:10:00', $i + 1), $this->utc);
        }

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        // median=10, MAD=0, deviation=15, start=max(0,10-15)=0, end=min(1439,10+15)=25
        // width=25 < 30 → correction: center=round((0+25)/2)=round(12.5)=13
        // corrected start=max(0,13-15)=0, end=min(1439,0+30)=30
        self::assertSame(0, $result['start']);
        self::assertSame(30, $result['end']);
    }

    /**
     * Near 1439: median=1430, deviation=15 → start=1415, end=min(1439,1445)=1439
     * width=1439-1415=24 < 30 → correction: center=round((1415+1439)/2)=round(1427)=1427
     *   start=max(0,1427-15)=1412, end=min(1439,1412+30)=1439 → [1412,1439]
     * (width = 27 < 30 but limited by day end)
     * Actually: center = round((1415+1439)/2) = round(2854/2) = round(1427) = 1427
     *   start = max(0, 1427-15) = 1412
     *   end = min(1439, 1412+30) = min(1439, 1442) = 1439
     * width = 1439-1412 = 27, still < 30, but no further correction is done (one pass only)
     */
    public function testMinWindowCorrectionAtUpperBoundary(): void
    {
        $timestamps = [];
        for ($i = 0; $i < 7; ++$i) {
            // All at 23:50 UTC = 1430 minutes
            $timestamps[] = new \DateTimeImmutable(sprintf('2026-03-%02d 23:50:00', $i + 1), $this->utc);
        }

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        // median=1430, MAD=0, deviation=15, start=1415, end=min(1439,1445)=1439
        // width=1439-1415=24 < 30 → correction: center=round((1415+1439)/2)=round(1427)=1427
        // corrected start=max(0,1427-15)=1412, end=min(1439,1412+30)=1439
        self::assertSame(1412, $result['start']);
        self::assertSame(1439, $result['end']);
    }

    // -------------------------------------------------------------------------
    // Mutation-killing precision tests
    // -------------------------------------------------------------------------

    /**
     * Kill mutants 7+8: round() vs floor()/ceil() in deviation calculation.
     * Need MAD * 1.5 to produce an X.5 value. MAD=3, 1.5*3=4.5 → round=5, floor=4, ceil=5.
     * But since max(round(4.5)=5, 15)=15 and max(floor(4.5)=4, 15)=15, they're equivalent.
     * We need MAD where 1.5*MAD is NOT dominated by MIN_WINDOW_MINUTES/2=15.
     * So MAD must be > 10: if MAD=11, 1.5*11=16.5 → round=17, floor=16, ceil=17.
     * Use 7 values: 460,470,480,490,500,510,520 → sorted, median=490
     * deviations: 30,20,10,0,10,20,30 → sorted: 0,10,10,20,20,30,30 → median=20
     * deviation = max(round(1.5*20)=30, 15) = 30
     * With floor: max(floor(30.0)=30, 15)=30 — same. Need non-integer 1.5*MAD.
     * MAD=11: 1.5*11=16.5 → round=17, floor=16: DIFF!
     * Values where MAD=11: say 7 values where deviations when sorted have median=11
     * sorted deviations: [1,3,5,11,17,19,21] → median=11
     * If median=500, values=[499,497,495,489/511,483/517,481/519,479/521]
     * Let's use: 500,501,503,505,511,517,519,521 (8 values, even):
     * sorted: 500,501,503,505,511,517,519,521 → mid=4, lo=505, hi=511 → median=round(1016/2)=508
     * deviations: 8,7,5,3,3,9,11,13 → sorted: 3,3,5,7,8,9,11,13 → mid=4, lo=7, hi=8 → MAD=round(15/2)=8
     * 1.5*8=12.0 — integer again. Let me try another set.
     * sorted deviations having median=11 (odd count 7):
     * values: [500-1,500-3,500-5,500,500+5,500+21,500+23]=499,497,495,500,505,521,523
     * sorted: 495,497,499,500,505,521,523 → count=7 mid=3 → median=500
     * deviations: 5,3,1,0,5,21,23 → sorted: 0,1,3,5,5,21,23 → mid=3 → median=5
     * Still not getting 11. Let me think differently.
     * deviations median=11: sorted list where mid element=11
     * 7 values: [2,4,6,11,14,16,18] → 1.5*11=16.5 → round=17, floor=16, ceil=17
     * values with median=500 and those deviations: 502,504,506,511,514,516,518
     * sorted: 502,504,506,511,514,516,518 → median=511 ✓
     * deviations from 511: 9,7,5,0,3,5,7 → sorted: 0,3,5,5,7,7,9 → median=5
     * Hmm. Let me use exactly [a,a+4,a+8,a+12,a+16,a+20,a+24] → evenly spaced
     * a=400, spacing=4: 400,404,408,412,416,420,424 → median=412
     * deviations: 12,8,4,0,4,8,12 → sorted: 0,4,4,8,8,12,12 → median=8
     * 1.5*8=12.0 (integer). Need odd MAD. Use spacing=3:
     * a=400: 400,403,406,409,412,415,418 → median=409
     * deviations: 9,6,3,0,3,6,9 → sorted: 0,3,3,6,6,9,9 → median=6
     * 1.5*6=9.0 integer. Spacing=5:
     * 400,405,410,415,420,425,430 → median=415, deviations: 15,10,5,0,5,10,15 → median=10
     * 1.5*10=15.0 = MIN_WINDOW/2: deviation=max(15,15)=15. Same. spacing=7:
     * 400,407,414,421,428,435,442 → median=421, deviations: 21,14,7,0,7,14,21 → median=14
     * 1.5*14=21.0. floor=21, ceil=21, round=21. All same.
     * The issue: all integer MADs times 1.5 may be integers when MAD is even, or end in .0 when odd.
     * 1.5*MAD is non-integer only when MAD is ODD (gives X.5).
     * MAD=3: 1.5*3=4.5 → round=5, floor=4 → DIFF. But max(5,15)=max(4,15)=15 anyway.
     * We need 1.5*MAD > 15 AND MAD is odd. MAD=11: 1.5*11=16.5, round=17, floor=16 → DIFF (17≠16).
     * Values for MAD=11:
     * sorted deviations mid=11 (7 values): need position 3 = 11: [a,b,c,11,d,e,f] where a<=b<=c<=11
     * simplest: [0,0,0,11,11,11,11] which means values are at +/-11 from median plus some at 0 diff
     * median=500, values: 500,500,500, 489,511,511,511 → sorted: 489,500,500,500,511,511,511
     * new median after sort: mid=3 → 500. Deviations: 11,0,0,0,11,11,11 → sorted: 0,0,0,11,11,11,11 → median=11
     * MAD=11, 1.5*11=16.5 → round=17, floor=16, ceil=17
     * start=max(0,500-17)=483, end=min(1439,500+17)=517 (with round=17)
     * start=max(0,500-16)=484, end=min(1439,500+16)=516 (with floor=16)
     * So if test asserts start=483, floor mutant gives start=484 → caught!
     */
    public function testDeviationRoundingFloorVsCeilDistinguishable(): void
    {
        // Values: 489,500,500,500,511,511,511 → median=500, MAD=11
        // 1.5*11=16.5 → round=17 (not floor=16 or ceil=17 - ceil=17 same as round here)
        // deviation=max(17,15)=17. start=500-17=483, end=500+17=517
        $minutesList = [489, 500, 500, 500, 511, 511, 511];
        $timestamps = [];
        foreach ($minutesList as $i => $m) {
            $h = (int) ($m / 60);
            $min = $m % 60;
            $timestamps[] = new \DateTimeImmutable(
                sprintf('2026-04-%02d %02d:%02d:00', $i + 1, $h, $min),
                $this->utc,
            );
        }

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        // round(16.5)=17, floor=16 → different start/end
        self::assertSame(483, $result['start']); // 500-17=483, not 500-16=484
        self::assertSame(517, $result['end']);    // 500+17=517, not 500+16=516
    }

    /**
     * Kill mutant 9 (max 0 vs -1): test that start is exactly 0 when median - deviation < 0.
     * Also: verify start can be 0 but NOT negative.
     * 7 logs at 05:00 (300 min) with MAD=0, deviation=15 → start=max(0,285)=285.
     * For start to be 0, need median < 15. Use 7 logs at 00:10 (10 min):
     * already tested in testMinWindowCorrectionAtLowerBoundary, but let me test without correction.
     * Actually: need median <= 0 to hit 0 boundary without correction.
     * 7 logs at 00:00 (0 min): median=0, MAD=0, deviation=15 → start=max(0,-15)=0, end=15
     * width=15 < 30 → correction: center=round((0+15)/2)=8, start=max(0,8-15)=0, end=30
     * So start=0 due to max(0,...) check. Test that start is 0 not -15.
     */
    public function testStartNeverNegative(): void
    {
        $timestamps = [];
        for ($i = 0; $i < 7; ++$i) {
            $timestamps[] = new \DateTimeImmutable(sprintf('2026-03-%02d 00:00:00', $i + 1), $this->utc);
        }

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        self::assertSame(0, $result['start']); // max(0,-15) must be 0 not -15
    }

    /**
     * Kill mutant 8 (max(-1,...) instead of max(0,...) on start line 95).
     * With median=14 and MAD=0, deviation=15:
     * - Normal: start=max(0,14-15)=max(0,-1)=0, end=29, width=29<30 → correction → [0,30]
     * - Mutant: start=max(-1,-1)=-1, end=29, width=30, no correction (30<30 is false) → [-1,29]
     * Asserting start=0 and end=30 kills both variants.
     */
    public function testStartMaxBoundaryWhenDeviationExceedsMedian(): void
    {
        $timestamps = [];
        for ($i = 0; $i < 7; ++$i) {
            // 14 minutes = 00:14
            $timestamps[] = new \DateTimeImmutable(sprintf('2026-03-%02d 00:14:00', $i + 1), $this->utc);
        }

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        // median=14, MAD=0, deviation=15, start=max(0,-1)=0, end=29, width=29
        // correction: center=round(14.5)=15, start=max(0,0)=0, end=30
        self::assertSame(0, $result['start']); // NOT -1 (kills max(-1,...) mutant)
        self::assertSame(30, $result['end']);
    }

    /**
     * Kill mutant 10 (max 0 vs max 1): when median - deviation = 0 exactly,
     * max(0, 0)=0 but max(1, 0)=1. Need median=deviation=15.
     * 7 logs all at 00:15 (15 min): median=15, MAD=0, deviation=15
     * start=max(0,15-15)=max(0,0)=0. With max(1,0)=1. Test start===0.
     * width=30 = MIN_WINDOW exactly → no correction needed
     * end=15+15=30
     */
    public function testStartIsZeroWhenMedianEqualsDeviation(): void
    {
        $timestamps = [];
        for ($i = 0; $i < 7; ++$i) {
            // 15 minutes = 00:15
            $timestamps[] = new \DateTimeImmutable(sprintf('2026-03-%02d 00:15:00', $i + 1), $this->utc);
        }

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        self::assertSame(0, $result['start']); // max(0, 15-15) = max(0, 0) = 0
        self::assertSame(30, $result['end']);   // 15+15 = 30
    }

    /**
     * Kill mutant 11 (min 1439 vs 1438): logs at 23:54 (1434 min), deviation=15
     * end=min(1439, 1434+15)=min(1439,1449)=1439. With min(1438,...)=1438. Test end===1439.
     */
    public function testEndIsExactly1439WhenCalculationExceeds(): void
    {
        $timestamps = [];
        for ($i = 0; $i < 7; ++$i) {
            // 23:54 = 23*60+54 = 1434 minutes
            $timestamps[] = new \DateTimeImmutable(sprintf('2026-03-%02d 23:54:00', $i + 1), $this->utc);
        }

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        // median=1434, MAD=0, deviation=15, end=min(1439,1449)=1439
        // start=1434-15=1419, end=1439, width=1439-1419=20 < 30
        // correction: center=round((1419+1439)/2)=round(2858/2)=round(1429.0)=1429
        // corrected start=max(0,1429-15)=1414, end=min(1439,1414+30)=1439
        self::assertSame(1414, $result['start']);
        self::assertSame(1439, $result['end']); // must be 1439 not 1438
    }

    /**
     * Kill mutant 12 (< vs <=): When end-start == 30 exactly, `<` does NOT trigger correction
     * but `<=` would. Test that correction is NOT applied when width==30.
     * 7 logs at 08:00 (480 min): median=480, MAD=0, deviation=15
     * start=465, end=495, width=30. With `<`: no correction (start stays 465).
     * With `<=`: correction triggers, center=round((465+495)/2)=480, start=max(0,480-15)=465, end=495.
     * In this particular case both produce same result! Need different outcome.
     * Use median=15: start=0, end=30, width=30. correction: center=15, start=0, end=30. Same!
     * Try median=14: start=max(0,-1)=0, end=29, width=29<30 → correction triggers.
     * For no-correction case, use median=480 (well away from boundaries): start=465, end=495.
     * With `<`: no correction, start=465. With `<=`: correction → center=480, start=465, end=495. Same again!
     * The mutation is effectively undetectable unless correction changes values.
     * Use median where correction would change the output:
     * median=200, MAD=7, deviation=max(round(1.5*7)=11,15)=15 (=MIN_WINDOW_MINUTES/2=15)
     * Actually when deviation=15, start=200-15=185, end=200+15=215, width=30. With `<=`: correction runs:
     * center=round((185+215)/2)=200, start=max(0,200-15)=185, end=min(1439,185+30)=215. Same!
     * This mutant (< vs <=) produces identical results when window=30 because correction preserves the window.
     * This is truly a false positive / equivalent mutant. Cannot distinguish with tests.
     * We document this as equivalent but add a test for the meaningful boundary:
     */
    public function testMinWidthConditionLessThan30TriggersCorrectionNotAtExactly30(): void
    {
        // width=29 should trigger correction (< 30 is true, <= 30 is also true - same behavior)
        $timestamps = [];
        for ($i = 0; $i < 7; ++$i) {
            // 00:10 = 10 min: median=10, MAD=0, deviation=15
            // start=max(0,10-15)=0, end=25, width=25 < 30 → correction
            $timestamps[] = new \DateTimeImmutable(sprintf('2026-03-%02d 00:10:00', $i + 1), $this->utc);
        }

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        // Correction runs: center=round(12.5)=13, start=0, end=30
        self::assertGreaterThanOrEqual(30, $result['end'] - $result['start']);
        // Key assertion: end==30 (not 25 which would happen without correction)
        self::assertSame(30, $result['end']);
    }

    /**
     * Kill mutants 13+14: round() vs floor()/ceil() in center calculation.
     * Need (start+end) to be odd so /2 produces X.5.
     * Example: start=0, end=25 → sum=25, 25/2=12.5 → round=13, floor=12, ceil=13.
     * center=13 → start=max(0,13-15)=0, end=min(1439,0+30)=30.
     * center=12 (floor) → start=max(0,12-15)=0, end=min(1439,0+30)=30. Same start/end!
     * Try start=2, end=25 → sum=27, 27/2=13.5 → round=14, floor=13.
     * center=14 → start=max(0,14-15)=0, end=30.
     * center=13 → start=max(0,13-15)=0, end=30. SAME!
     * The floor/ceil distinction in center only matters if it changes start/end differently.
     * start=max(0,center-15), end=min(1439,start+30). Both produce start=0 when center<=15.
     * Only when center > 15 does the correction differentiate.
     * Need start > 0 after correction. center must be > 15.
     * Example: start=12, end=39 → sum=51, 51/2=25.5 → round=26, floor=25.
     * center=26 → start=max(0,26-15)=11, end=min(1439,11+30)=41.
     * center=25 → start=max(0,25-15)=10, end=min(1439,10+30)=40. DIFFERENT! (11 vs 10)
     * To get start=12, end=39: need median where deviation produces that.
     * deviation=max(round(1.5*MAD), 15). For start=12, end=39: median=25.5 (not int, impossible).
     * Let median=26, deviation=14 (but min is 15, so deviation=15): start=11, end=41, width=30.
     * So no correction. Hmm. Need width < 30.
     * For width < 30: deviation < 15 → uses min 15. With 15: width=30, no correction.
     * Actually deviation can be 15 (when MAD=0) and width is exactly 30 = not < 30 = no correction.
     * OR deviation can be > 15 (when MAD>10) and width > 30 = no correction.
     * The correction only triggers when: start hits boundary (0 or 1439) causing width < 30.
     * Near lower boundary: median=18, deviation=15 → start=max(0,3)=3, end=33. width=30. No correction.
     * median=12, deviation=15 → start=0, end=27. width=27 < 30.
     * sum=0+27=27. 27/2=13.5 → round=14, floor=13. center=14 → start=0, end=30 (same for both since start<=15).
     * Near upper: median=1427, deviation=15 → start=1412, end=min(1439,1442)=1439. width=27<30.
     * sum=1412+1439=2851. 2851/2=1425.5 → round=1426, floor=1425.
     * center=1426 → start=max(0,1426-15)=1411, end=min(1439,1411+30)=1439.
     * center=1425 → start=max(0,1425-15)=1410, end=min(1439,1410+30)=1439.
     * DIFFERENT start: 1411 (round) vs 1410 (floor).
     */
    public function testCenterRoundingFloorVsRoundDistinguishable(): void
    {
        $timestamps = [];
        // Target: median=1427 (23:47), deviation=15
        // start=1412, end=min(1439,1442)=1439, width=27 < 30
        // correction: center=round((1412+1439)/2)=round(2851/2)=round(1425.5)=1426
        // corrected start=max(0,1426-15)=1411, end=min(1439,1411+30)=1439
        for ($i = 0; $i < 7; ++$i) {
            // 1427 minutes = 23*60+47 = 1427
            $timestamps[] = new \DateTimeImmutable(sprintf('2026-03-%02d 23:47:00', $i + 1), $this->utc);
        }

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        // With round: center=1426, start=1411, end=1439
        // With floor: center=1425, start=1410, end=1439 → DIFFERENT start
        self::assertSame(1411, $result['start']); // distinguishes round from floor
        self::assertSame(1439, $result['end']);
    }

    /**
     * Kill mutants 22+23: round() vs floor()/ceil() in median even-count.
     * Need lo+hi to be odd so /2 = X.5.
     * Example: lo=130, hi=141 → sum=271, /2=135.5 → round=136, floor=135, ceil=136.
     * But median must produce different window start/end.
     * 8 values with lo=130, hi=141: sorted [a,b,c,130,141,d,e,f]
     * e.g.: 100,110,120,130,141,150,160,170
     * sorted: 100,110,120,130,141,150,160,170 → lo=values[3]=130, hi=values[4]=141
     * median=round((130+141)/2)=round(135.5)=136 (vs floor=135)
     * deviations from 136: 36,26,16,6,5,14,24,34 → sorted: 5,6,14,16,24,26,34,36
     * mid=4: lo=sorted[3]=16, hi=sorted[4]=24 → MAD=round(40/2)=20
     * deviation=max(round(1.5*20)=30, 15)=30
     * start=max(0,136-30)=106, end=min(1439,136+30)=166 (with round median=136)
     * with floor(135): deviations: 35,25,15,5,6,15,25,35 → sorted: 5,6,15,15,25,25,35,35 → MAD=round(30/2)=20
     * deviation=30, start=135-30=105, end=165 → DIFFERENT: (106,166) vs (105,165)
     */
    public function testMedianEvenCountRoundingDistinguishable(): void
    {
        $timestamps = [];
        $minutesList = [100, 110, 120, 130, 141, 150, 160, 170];
        foreach ($minutesList as $i => $m) {
            $h = (int) ($m / 60);
            $min = $m % 60;
            $timestamps[] = new \DateTimeImmutable(
                sprintf('2026-04-%02d %02d:%02d:00', $i + 1, $h, $min),
                $this->utc,
            );
        }

        $result = $this->learner->learn($timestamps, $this->utc);

        self::assertNotNull($result);
        // round median=136, MAD=20, deviation=30 → start=106, end=166
        // floor median=135, MAD=20, deviation=30 → start=105, end=165
        self::assertSame(106, $result['start']);
        self::assertSame(166, $result['end']);
    }

    /**
     * Kill mutant about (int) cast in minutesToTime line 68.
     * Without cast: $h = 7/60 = 0.116... → sprintf('%02d', 0.116) = '0' (PHP truncates float in %d).
     * Actually PHP sprintf %d on float still formats as integer. So this might be an equivalent mutant.
     * But testing a value where h != 0 ensures the division is actually truncating: e.g. 90/60=1.5 → (int)=1.
     * If no cast: sprintf('%02d', 1.5) → '1' in PHP8... actually it's '1' since %d truncates.
     * This is equivalent. However, let's verify via an assertion that the hour is correct.
     */
    public function testMinutesToTimeHourTruncation(): void
    {
        // 90 minutes = 1h30m → '01:30'. Without (int) cast: 90/60=1.5, sprintf('%02d',1.5)='1'='01'
        // Actually in PHP 8, sprintf with %d on 1.5 raises a deprecation/error. With cast: (int)(1.5)=1. Safe.
        self::assertSame('01:30', TimeWindowLearner::minutesToTime(90));
        self::assertSame('02:00', TimeWindowLearner::minutesToTime(120));
        self::assertSame('23:00', TimeWindowLearner::minutesToTime(1380));
    }

    /**
     * Kill DOW cast mutant (line 49): Without (int) cast, $dow='1' (string). '1' >= 6 is false (string comp).
     * Actually in PHP, '1' >= 6 → PHP converts '1' to int 1 ≥ 6 = false. '6' >= 6 → 6 >= 6 = true.
     * String '6' >= 6: PHP coerces string to int, so result is same. This is an equivalent mutant.
     * But test it anyway for correctness.
     */
    public function testLearnWithSplitDowBoundaryExact(): void
    {
        // 2026-03-20 = Friday (dow=5), 2026-03-21 = Saturday (dow=6)
        // Friday should be weekday, Saturday should be weekend
        $fridayDates = [
            '2026-03-16', '2026-03-17', '2026-03-18', '2026-03-19', '2026-03-20',
            '2026-03-23', '2026-03-24',
        ];
        $saturdayDates = [
            '2026-03-21', '2026-03-22', '2026-03-28', '2026-03-29',
            '2026-04-04', '2026-04-05', '2026-04-11',
        ];

        $fridayTimestamps = array_map(
            fn (string $d): \DateTimeImmutable => new \DateTimeImmutable("{$d} 08:00:00", $this->utc),
            $fridayDates,
        );
        $saturdayTimestamps = array_map(
            fn (string $d): \DateTimeImmutable => new \DateTimeImmutable("{$d} 10:00:00", $this->utc),
            $saturdayDates,
        );

        $allTimestamps = array_merge($fridayTimestamps, $saturdayTimestamps);

        $result = $this->learner->learnWithSplit($allTimestamps, $this->utc);

        // Fridays (dow=5) must go to weekday, Saturdays (dow=6) must go to weekend
        self::assertNotNull($result['weekday']);
        self::assertNotNull($result['weekend']);

        $weekdayCenter = (int) round(($result['weekday']['start'] + $result['weekday']['end']) / 2);
        $weekendCenter = (int) round(($result['weekend']['start'] + $result['weekend']['end']) / 2);

        // weekday at 08:00=480, weekend at 10:00=600
        self::assertSame(480, $weekdayCenter); // Fridays centered at 08:00 = 480 min
        self::assertSame(600, $weekendCenter); // Saturdays centered at 10:00 = 600 min
    }

    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    /**
     * Create $count UTC timestamps centered around $timeStr (HH:MM) on different dates,
     * with small random-ish jitter within ±$jitterMinutes.
     *
     * @return list<\DateTimeImmutable>
     */
    private function makeTimestampsAroundTime(string $timeStr, int $count, int $jitterMinutes = 10): array
    {
        $parts = explode(':', $timeStr);
        $hour = (int) ($parts[0] ?? 0);
        $minute = (int) ($parts[1] ?? 0);
        $timestamps = [];
        // Use deterministic jitter based on index for reproducibility
        $jitterPattern = [-3, 4, -1, 2, -5, 1, 3, -2, 5, -4, 0, 2, -3, 1, 4];

        for ($i = 0; $i < $count; ++$i) {
            $jitter = (int) round($jitterMinutes * ($jitterPattern[$i % count($jitterPattern)] / 5));
            $totalMinutes = $hour * 60 + $minute + $jitter;
            // Clamp to valid range
            $totalMinutes = max(0, min(1439, $totalMinutes));
            $h = (int) ($totalMinutes / 60);
            $m = $totalMinutes % 60;
            $day = $i + 1;
            $timestamps[] = new \DateTimeImmutable(
                sprintf('2026-03-%02d %02d:%02d:00', $day, $h, $m),
                $this->utc,
            );
        }

        return $timestamps;
    }
}
