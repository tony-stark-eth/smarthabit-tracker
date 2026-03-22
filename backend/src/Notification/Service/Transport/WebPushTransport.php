<?php

declare(strict_types=1);

namespace App\Notification\Service\Transport;

use App\Notification\Service\WebPushServiceInterface;

final readonly class WebPushTransport implements PushTransportInterface
{
    public function __construct(
        private WebPushServiceInterface $webPushService,
    ) {
    }

    public function supports(string $type): bool
    {
        return $type === 'web_push';
    }

    /**
     * @param array<string, mixed> $subscription
     */
    public function send(array $subscription, PushPayload $payload): PushResult
    {
        $jsonPayload = json_encode([
            'title' => $payload->title,
            'body' => $payload->body,
            'habitId' => $payload->habitId,
        ], \JSON_THROW_ON_ERROR);

        /** @var array{endpoint: string, keys: array{p256dh: string, auth: string}} $subscription */
        $result = $this->webPushService->send($subscription, $jsonPayload);

        if ($result['success']) {
            return PushResult::success($result['statusCode']);
        }

        $shouldRemove = $result['statusCode'] === 410;

        return PushResult::failure(
            statusCode: $result['statusCode'] ?? 0,
            reason: $result['reason'] ?? 'Unknown error',
            shouldRemove: $shouldRemove,
        );
    }
}
