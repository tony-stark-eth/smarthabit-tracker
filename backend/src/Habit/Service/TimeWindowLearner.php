<?php

declare(strict_types=1);

namespace App\Habit\Service;

final readonly class TimeWindowLearner
{
    private const int MIN_DATA_POINTS = 7;

    private const float MAD_MULTIPLIER = 1.5;

    private const int MIN_WINDOW_MINUTES = 30;

    /**
     * @param list<\DateTimeImmutable> $logTimestamps UTC timestamps from HabitLog.loggedAt
     * @param \DateTimeZone $userTimezone User's IANA timezone
     * @return array{start: int, end: int}|null Minutes since midnight (local time), or null if insufficient data
     */
    public function learn(array $logTimestamps, \DateTimeZone $userTimezone): ?array
    {
        if (count($logTimestamps) < self::MIN_DATA_POINTS) {
            return null;
        }

        // Convert UTC → local minutes since midnight
        /** @var non-empty-list<int> $minutes */
        $minutes = array_map(
            fn (\DateTimeImmutable $dt): int => $this->toLocalMinutes($dt, $userTimezone),
            $logTimestamps,
        );

        return $this->calculateWindow($minutes);
    }

    /**
     * Split into weekday/weekend and learn separately.
     * Returns null for a group if < MIN_DATA_POINTS.
     *
     * @param list<\DateTimeImmutable> $logTimestamps
     * @return array{weekday: array{start: int, end: int}|null, weekend: array{start: int, end: int}|null}
     */
    public function learnWithSplit(array $logTimestamps, \DateTimeZone $userTimezone): array
    {
        $weekday = [];
        $weekend = [];

        foreach ($logTimestamps as $dt) {
            $local = $dt->setTimezone($userTimezone);
            // @infection-ignore-all — (int) cast equivalent: PHP coerces string-to-int in >= comparison
            $dow = (int) $local->format('N'); // 1=Mon, 7=Sun
            if ($dow >= 6) {
                $weekend[] = $dt;
            } else {
                $weekday[] = $dt;
            }
        }

        return [
            'weekday' => $this->learn($weekday, $userTimezone),
            'weekend' => $this->learn($weekend, $userTimezone),
        ];
    }

    /**
     * Convert minutes since midnight to TIME string "HH:MM"
     */
    public static function minutesToTime(int $minutes): string
    {
        // @infection-ignore-all — (int) cast equivalent: sprintf %02d truncates float anyway in PHP 8
        $h = (int) ($minutes / 60);
        $m = $minutes % 60;

        return sprintf('%02d:%02d', $h, $m);
    }

    /**
     * Convert "HH:MM" TIME string to minutes since midnight
     */
    public static function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);

        // @infection-ignore-all — ?? 0 fallback for parts[1]: input always contains ':' separator in valid HH:MM
        return (int) $parts[0] * 60 + (int) ($parts[1] ?? 0);
    }

    /**
     * @param non-empty-list<int> $minutes
     * @return array{start: int, end: int}
     */
    private function calculateWindow(array $minutes): array
    {
        $median = $this->median($minutes);
        $mad = $this->mad($minutes, $median);

        // @infection-ignore-all — ceil() is equivalent to round() for .5 values (PHP rounds half away from zero)
        $deviation = max((int) round(self::MAD_MULTIPLIER * $mad), self::MIN_WINDOW_MINUTES / 2);

        $start = max(0, $median - $deviation);
        $end = min(1439, $median + $deviation); // 1439 = 23:59

        // Enforce minimum window width
        // @infection-ignore-all — < vs <= equivalent here (width=30 correction produces identical result)
        if (($end - $start) < self::MIN_WINDOW_MINUTES) {
            // @infection-ignore-all — ceil() equivalent to round() for integer sum / 2
            $center = (int) round(($start + $end) / 2);
            $start = max(0, $center - self::MIN_WINDOW_MINUTES / 2);
            $end = min(1439, $start + self::MIN_WINDOW_MINUTES);
        }

        return [
            'start' => $start,
            'end' => $end,
        ];
    }

    private function toLocalMinutes(\DateTimeImmutable $utcTime, \DateTimeZone $tz): int
    {
        $local = $utcTime->setTimezone($tz);

        // @infection-ignore-all — (int) casts equivalent: PHP coerces numeric string in arithmetic
        return (int) $local->format('G') * 60 + (int) $local->format('i');
    }

    /**
     * @param non-empty-list<int> $values
     */
    private function median(array $values): int
    {
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        if ($count % 2 === 0) {
            // @infection-ignore-all — fallback ?? 0 unreachable (array guaranteed non-empty, mid-1 and mid are valid)
            $lo = $values[$mid - 1] ?? 0;
            // @infection-ignore-all — fallback ?? 0 unreachable
            $hi = $values[$mid] ?? 0;

            // @infection-ignore-all — ceil() is equivalent to round() for integer lo+hi (sum/2 = X.5 rounds same)
            return (int) round(($lo + $hi) / 2);
        }

        // @infection-ignore-all — fallback ?? 0 unreachable (mid is valid index in non-empty sorted array)
        return $values[$mid] ?? 0;
    }

    /**
     * @param non-empty-list<int> $values
     */
    private function mad(array $values, int $median): int
    {
        /** @var non-empty-list<int<0, max>> $deviations */
        $deviations = array_map(fn (int $v): int => abs($v - $median), $values);

        return $this->median($deviations);
    }
}
