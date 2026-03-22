<?php

declare(strict_types=1);

namespace App\Shared\ValueObject;

final readonly class TimeWindow
{
    public function __construct(
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {
        if ($start >= $end) {
            throw new \InvalidArgumentException('Start must be before end.');
        }
    }

    public function contains(\DateTimeImmutable $time): bool
    {
        return $time >= $this->start && $time <= $this->end;
    }
}
