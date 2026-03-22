<?php

declare(strict_types=1);

namespace App\Notification\Service\Transport;

interface PushTransportInterface
{
    public function supports(string $type): bool;

    /**
     * @param array<string, mixed> $subscription
     */
    public function send(array $subscription, PushPayload $payload): PushResult;
}
