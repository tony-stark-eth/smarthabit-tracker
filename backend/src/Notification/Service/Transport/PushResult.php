<?php

declare(strict_types=1);

namespace App\Notification\Service\Transport;

final readonly class PushResult
{
    public function __construct(
        public bool $success,
        public ?int $statusCode = null,
        public ?string $reason = null,
        public bool $shouldRemoveSubscription = false,
    ) {
    }

    public static function success(?int $statusCode = null): self
    {
        return new self(
            success: true,
            statusCode: $statusCode,
        );
    }

    public static function failure(int $statusCode, string $reason, bool $shouldRemove = false): self
    {
        return new self(
            success: false,
            statusCode: $statusCode,
            reason: $reason,
            shouldRemoveSubscription: $shouldRemove,
        );
    }
}
