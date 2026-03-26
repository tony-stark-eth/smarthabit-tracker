<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\ValueObject;

use App\Shared\ValueObject\TimeWindow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TimeWindow::class)]
final class TimeWindowTest extends TestCase
{
    public function testContainsReturnsTrueForTimeWithinWindow(): void
    {
        $start = \Carbon\CarbonImmutable::parse('2024-01-01 08:00:00');
        $end = \Carbon\CarbonImmutable::parse('2024-01-01 10:00:00');
        $window = new TimeWindow($start, $end);

        self::assertTrue($window->contains(\Carbon\CarbonImmutable::parse('2024-01-01 09:00:00')));
    }

    public function testContainsReturnsTrueForTimeAtWindowBoundary(): void
    {
        $start = \Carbon\CarbonImmutable::parse('2024-01-01 08:00:00');
        $end = \Carbon\CarbonImmutable::parse('2024-01-01 10:00:00');
        $window = new TimeWindow($start, $end);

        self::assertTrue($window->contains($start));
        self::assertTrue($window->contains($end));
    }

    public function testContainsReturnsFalseForTimeBeforeWindow(): void
    {
        $start = \Carbon\CarbonImmutable::parse('2024-01-01 08:00:00');
        $end = \Carbon\CarbonImmutable::parse('2024-01-01 10:00:00');
        $window = new TimeWindow($start, $end);

        self::assertFalse($window->contains(\Carbon\CarbonImmutable::parse('2024-01-01 07:59:59')));
    }

    public function testContainsReturnsFalseForTimeAfterWindow(): void
    {
        $start = \Carbon\CarbonImmutable::parse('2024-01-01 08:00:00');
        $end = \Carbon\CarbonImmutable::parse('2024-01-01 10:00:00');
        $window = new TimeWindow($start, $end);

        self::assertFalse($window->contains(\Carbon\CarbonImmutable::parse('2024-01-01 10:00:01')));
    }

    public function testConstructorThrowsWhenStartEqualsEnd(): void
    {
        $time = \Carbon\CarbonImmutable::parse('2024-01-01 08:00:00');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Start must be before end.');

        new TimeWindow($time, $time);
    }

    public function testConstructorThrowsWhenStartIsAfterEnd(): void
    {
        $start = \Carbon\CarbonImmutable::parse('2024-01-01 10:00:00');
        $end = \Carbon\CarbonImmutable::parse('2024-01-01 08:00:00');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Start must be before end.');

        new TimeWindow($start, $end);
    }
}
