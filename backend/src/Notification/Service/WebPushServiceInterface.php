<?php

declare(strict_types=1);

namespace App\Notification\Service;

interface WebPushServiceInterface
{
    /**
     * @param array{endpoint: string, keys: array{p256dh: string, auth: string}} $subscription
     *
     * @return array{success: bool, statusCode: int|null, reason: string|null}
     */
    public function send(array $subscription, string $payload): array;
}
