<?php

declare(strict_types=1);

namespace App\Notification\Message;

final readonly class NotifyHabitMessage
{
    public function __construct(
        public string $habitId,
        public string $userId,
    ) {
    }
}
