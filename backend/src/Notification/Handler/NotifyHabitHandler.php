<?php

declare(strict_types=1);

namespace App\Notification\Handler;

use App\Auth\Entity\User;
use App\Habit\Entity\Habit;
use App\Notification\Entity\NotificationLog;
use App\Notification\Enum\NotificationChannel;
use App\Notification\Enum\NotificationStatus;
use App\Notification\Message\NotifyHabitMessage;
use App\Notification\Service\WebPushService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class NotifyHabitHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private WebPushService $webPushService,
    ) {
    }

    public function __invoke(NotifyHabitMessage $message): void
    {
        $user = $this->em->find(User::class, $message->userId);
        $habit = $this->em->find(Habit::class, $message->habitId);
        if (! $user instanceof User || ! $habit instanceof Habit) {
            return;
        }

        /** @var array<int, array{endpoint: string, keys: array{p256dh: string, auth: string}, device_name: string, last_seen: string, type: string}> $subscriptions */
        $subscriptions = $user->getPushSubscriptions() ?? [];

        foreach ($subscriptions as $index => $subscription) {
            if ($subscription['type'] !== 'web_push') {
                continue;
            }

            $payload = json_encode([
                'title' => 'SmartHabit',
                'body' => $habit->getName(),
                'habitId' => $habit->getId()->toRfc4122(),
            ], \JSON_THROW_ON_ERROR);

            $result = $this->webPushService->send($subscription, $payload);

            $log = new NotificationLog(
                user: $user,
                channel: NotificationChannel::WEB_PUSH,
                status: $result['success'] ? NotificationStatus::SENT : NotificationStatus::FAILED,
                sentAt: new \DateTimeImmutable(),
                message: $habit->getName(),
                habit: $habit,
            );
            $this->em->persist($log);

            // HTTP 410 Gone → remove stale subscription
            if ($result['statusCode'] === 410) {
                /** @var array<int, array{endpoint: string, keys: array<string, string>, device_name: string, last_seen: string, type: string}> $subs */
                $subs = $user->getPushSubscriptions() ?? [];
                unset($subs[$index]);
                $user->setPushSubscriptions(array_values($subs));
            }
        }

        $this->em->flush();
    }
}
