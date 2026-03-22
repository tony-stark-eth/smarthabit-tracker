<?php

declare(strict_types=1);

namespace App\Notification\Service\Transport;

final readonly class PushPayload
{
    public function __construct(
        public string $title,
        public string $body,
        public string $habitId,
    ) {
    }
}
