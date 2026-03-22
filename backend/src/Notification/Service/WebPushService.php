<?php

declare(strict_types=1);

namespace App\Notification\Service;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

final readonly class WebPushService implements WebPushServiceInterface
{
    private WebPush $webPush;

    public function __construct(string $vapidPublicKey, string $vapidPrivateKey)
    {
        $this->webPush = new WebPush([
            'VAPID' => [
                'subject' => 'mailto:noreply@smarthabit.de',
                'publicKey' => $vapidPublicKey,
                'privateKey' => $vapidPrivateKey,
            ],
        ]);
    }

    /**
     * @param array{endpoint: string, keys: array{p256dh: string, auth: string}} $subscription
     *
     * @return array{success: bool, statusCode: int|null, reason: string|null}
     */
    public function send(array $subscription, string $payload): array
    {
        $sub = Subscription::create([
            'endpoint' => $subscription['endpoint'],
            'publicKey' => $subscription['keys']['p256dh'],
            'authToken' => $subscription['keys']['auth'],
        ]);

        $report = $this->webPush->sendOneNotification($sub, $payload);

        $response = $report->getResponse();

        return [
            'success' => $report->isSuccess(),
            'statusCode' => $response?->getStatusCode(),
            'reason' => $report->isSuccess() ? null : $report->getReason(),
        ];
    }
}
