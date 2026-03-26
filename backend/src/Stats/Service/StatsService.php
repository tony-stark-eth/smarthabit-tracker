<?php

declare(strict_types=1);

namespace App\Stats\Service;

final readonly class StatsService
{
    /**
     * Calculate current streak (consecutive days with at least one log).
     *
     * @param list<\DateTimeImmutable> $logDates Dates of logs (local time, sorted DESC)
     */
    public function currentStreak(array $logDates): int
    {
        if (\count($logDates) === 0) {
            return 0;
        }

        // Group by date string, count consecutive days from today backward
        $dates = array_values(array_unique(array_map(
            fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'),
            $logDates,
        )));
        sort($dates);
        $dates = array_values(array_reverse($dates));

        $today = \Carbon\CarbonImmutable::now()->format('Y-m-d');
        $yesterday = \Carbon\CarbonImmutable::now()->subDays(1)->format('Y-m-d');

        // Streak must start from today or yesterday
        $first = $dates[0] ?? '';
        if ($first !== $today && $first !== $yesterday) {
            return 0;
        }

        $streak = 1;
        $counter = \count($dates);
        for ($i = 1; $i < $counter; $i++) {
            $prevStr = $dates[$i - 1] ?? '';
            $currStr = $dates[$i] ?? '';
            $prev = new \DateTimeImmutable($prevStr);
            $curr = new \DateTimeImmutable($currStr);
            $diff = $prev->diff($curr)->days;
            if ($diff === 1) {
                ++$streak;
            } else {
                break;
            }
        }

        return $streak;
    }

    /**
     * Calculate longest streak ever.
     *
     * @param list<\DateTimeImmutable> $logDates
     */
    public function longestStreak(array $logDates): int
    {
        if (\count($logDates) === 0) {
            return 0;
        }

        $dates = array_values(array_unique(array_map(
            fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'),
            $logDates,
        )));
        sort($dates);

        $longest = 1;
        $current = 1;
        $counter = \count($dates);

        for ($i = 1; $i < $counter; $i++) {
            $prev = new \DateTimeImmutable($dates[$i - 1] ?? '');
            $curr = new \DateTimeImmutable($dates[$i] ?? '');
            if ($prev->diff($curr)->days === 1) {
                ++$current;
                $longest = max($longest, $current);
            } else {
                $current = 1;
            }
        }

        return max($longest, $current);
    }

    /**
     * Completion rate: completed days / total days in period.
     *
     * @param list<\DateTimeImmutable> $logDates
     */
    public function completionRate(array $logDates, int $periodDays): float
    {
        if ($periodDays <= 0) {
            return 0.0;
        }

        $uniqueDays = \count(array_unique(
            array_map(fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $logDates),
        ));

        return min(1.0, $uniqueDays / $periodDays);
    }

    /**
     * Average completion time (median minutes since midnight).
     *
     * @param list<\DateTimeImmutable> $logTimes Local times
     */
    public function averageCompletionTime(array $logTimes): ?int
    {
        if (\count($logTimes) === 0) {
            return null;
        }

        $minutes = array_values(array_map(
            fn (\DateTimeImmutable $t): int => (int) $t->format('G') * 60 + (int) $t->format('i'),
            $logTimes,
        ));
        sort($minutes);

        $total = \count($minutes);
        $mid = (int) ($total / 2);

        if ($total % 2 === 0) {
            $a = $minutes[$mid - 1] ?? 0;
            $b = $minutes[$mid] ?? 0;

            return (int) round(($a + $b) / 2);
        }

        return $minutes[$mid] ?? 0;
    }

    /**
     * Trend: compare current 30d completion rate vs previous 30d.
     * Returns positive = improving, negative = declining, 0 = stable.
     */
    public function rateTrend(float $currentRate, float $previousRate): float
    {
        return round($currentRate - $previousRate, 2);
    }

    /**
     * Build weekday heatmap data: count of logs per day-of-week (1=Mon, 7=Sun).
     *
     * @param list<\DateTimeImmutable> $logDates
     * @return array<int<1,7>, int> Day number => count
     */
    public function weekdayHeatmap(array $logDates): array
    {
        /** @var array<int<1,7>, int> $map */
        $map = [
            1 => 0,
            2 => 0,
            3 => 0,
            4 => 0,
            5 => 0,
            6 => 0,
            7 => 0,
        ];
        foreach ($logDates as $date) {
            $dow = (int) $date->format('N');
            if (isset($map[$dow])) {
                $map[$dow]++;
            }
        }

        return $map;
    }

    /**
     * Build time-of-day heatmap: count of logs per hour (0-23).
     *
     * @param list<\DateTimeImmutable> $logTimes Local times
     * @return list<int> Index is the hour (0-23), value is count
     */
    public function timeHeatmap(array $logTimes): array
    {
        $map = [
            0 => 0,
            1 => 0,
            2 => 0,
            3 => 0,
            4 => 0,
            5 => 0,
            6 => 0,
            7 => 0,
            8 => 0,
            9 => 0,
            10 => 0,
            11 => 0,
            12 => 0,
            13 => 0,
            14 => 0,
            15 => 0,
            16 => 0,
            17 => 0,
            18 => 0,
            19 => 0,
            20 => 0,
            21 => 0,
            22 => 0,
            23 => 0,
        ];
        foreach ($logTimes as $time) {
            $hour = (int) $time->format('G');
            if (isset($map[$hour])) {
                $map[$hour]++;
            }
        }

        return $map;
    }
}
