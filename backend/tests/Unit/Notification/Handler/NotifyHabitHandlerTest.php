<?php

declare(strict_types=1);

namespace Tests\Unit\Notification\Handler;

use App\Auth\Entity\User;
use App\Habit\Entity\Habit;
use App\Habit\Enum\HabitFrequency;
use App\Household\Entity\Household;
use App\Notification\Entity\NotificationLog;
use App\Notification\Enum\NotificationChannel;
use App\Notification\Enum\NotificationStatus;
use App\Notification\Handler\NotifyHabitHandler;
use App\Notification\Message\NotifyHabitMessage;
use App\Notification\Service\Transport\PushPayload;
use App\Notification\Service\Transport\PushResult;
use App\Notification\Service\Transport\PushTransportInterface;
use App\Notification\Service\Transport\TransportRegistry;
use DG\BypassFinals;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NotifyHabitHandler::class)]
final class NotifyHabitHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        BypassFinals::enable();
    }

    public function testInvokeReturnsEarlyWhenUserNotFound(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);
        $em->expects(self::never())->method('flush');

        $registry = new TransportRegistry([]);

        $handler = new NotifyHabitHandler($em, $registry);
        $handler(new NotifyHabitMessage('habit-id', 'user-id'));
    }

    public function testInvokeReturnsEarlyWhenHabitNotFound(): void
    {
        $household = new Household('Test Household');
        $user = new User($household, 'user@example.com', 'hashed', 'Alice');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            static function (string $class) use ($user): mixed {
                if ($class === User::class) {
                    return $user;
                }

                return null;
            },
        );
        $em->expects(self::never())->method('flush');

        $registry = new TransportRegistry([]);

        $handler = new NotifyHabitHandler($em, $registry);
        $handler(new NotifyHabitMessage('habit-id', 'user-id'));
    }

    public function testInvokeSkipsSubscriptionWithUnknownType(): void
    {
        [$em, $user, $habit] = $this->buildEmWithUserAndHabit([
            [
                'type' => 'unknown_transport',
                'endpoint' => 'https://example.com/push',
                'keys' => [
                    'p256dh' => 'key',
                    'auth' => 'auth',
                ],
                'device_name' => 'Phone',
                'last_seen' => '2024-01-01',
            ],
        ]);

        $em->expects(self::never())->method('persist');
        $em->expects(self::once())->method('flush');

        $registry = new TransportRegistry([]);

        $handler = new NotifyHabitHandler($em, $registry);
        $handler(new NotifyHabitMessage(
            $habit->getId()->toRfc4122(),
            $user->getId()->toRfc4122(),
        ));
    }

    public function testInvokeSendsViaTransportAndPersistsLog(): void
    {
        [$em, $user, $habit] = $this->buildEmWithUserAndHabit([
            [
                'type' => 'web_push',
                'endpoint' => 'https://example.com/push',
                'keys' => [
                    'p256dh' => 'p256dh-key',
                    'auth' => 'auth-key',
                ],
                'device_name' => 'Desktop',
                'last_seen' => '2024-01-01',
            ],
        ]);

        $transport = $this->createMock(PushTransportInterface::class);
        $transport->method('supports')->willReturn(true);
        $transport
            ->expects(self::once())
            ->method('send')
            ->with(
                self::anything(),
                self::callback(static fn (PushPayload $p): bool => $p->title === 'SmartHabit'),
            )
            ->willReturn(PushResult::success(201));

        $registry = new TransportRegistry([$transport]);

        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(NotificationLog::class));
        $em->expects(self::once())->method('flush');

        $handler = new NotifyHabitHandler($em, $registry);
        $handler(new NotifyHabitMessage(
            $habit->getId()->toRfc4122(),
            $user->getId()->toRfc4122(),
        ));
    }

    public function testInvokeRemovesSubscriptionWhenShouldRemoveIsTrue(): void
    {
        $subscription = [
            'type' => 'web_push',
            'endpoint' => 'https://example.com/push',
            'keys' => [
                'p256dh' => 'p256dh-key',
                'auth' => 'auth-key',
            ],
            'device_name' => 'Phone',
            'last_seen' => '2024-01-01',
        ];

        [$em, $user, $habit] = $this->buildEmWithUserAndHabit([$subscription]);

        $transport = self::createStub(PushTransportInterface::class);
        $transport->method('supports')->willReturn(true);
        $transport->method('send')->willReturn(
            PushResult::failure(410, 'Gone', shouldRemove: true),
        );

        $registry = new TransportRegistry([$transport]);

        $em->method('persist');
        $em->expects(self::once())->method('flush');

        $handler = new NotifyHabitHandler($em, $registry);
        $handler(new NotifyHabitMessage(
            $habit->getId()->toRfc4122(),
            $user->getId()->toRfc4122(),
        ));

        self::assertSame([], $user->getPushSubscriptions());
    }

    public function testInvokeLogsFailureReasonOnFailedSend(): void
    {
        $household = new Household('Test Household');
        $user = new User($household, 'user@example.com', 'hashed', 'Alice');
        $user->setPushSubscriptions([
            [
                'type' => 'web_push',
                'endpoint' => 'https://example.com/push',
                'keys' => [
                    'p256dh' => 'p256dh-key',
                    'auth' => 'auth-key',
                ],
                'device_name' => 'Desktop',
                'last_seen' => '2024-01-01',
            ],
        ]);
        $habit = new Habit(
            household: $household,
            name: 'Morning Run',
            frequency: HabitFrequency::DAILY,
        );

        $transport = self::createStub(PushTransportInterface::class);
        $transport->method('supports')->willReturn(true);
        $transport->method('send')->willReturn(
            PushResult::failure(500, 'Server Error'),
        );

        $registry = new TransportRegistry([$transport]);

        $persistedLog = null;
        $em = self::createStub(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            static fn (string $class): mixed => match ($class) {
                User::class => $user,
                Habit::class => $habit,
                default => null,
            },
        );
        $em->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persistedLog): void {
                if ($entity instanceof NotificationLog) {
                    $persistedLog = $entity;
                }
            },
        );

        $handler = new NotifyHabitHandler($em, $registry);
        $handler(new NotifyHabitMessage(
            $habit->getId()->toRfc4122(),
            $user->getId()->toRfc4122(),
        ));

        self::assertInstanceOf(NotificationLog::class, $persistedLog);
        self::assertSame('Server Error', $persistedLog->getErrorReason());
    }

    public function testInvokeSkipsNullTransportAndProcessesNextSubscription(): void
    {
        // First subscription type has no matching transport (continue, not break)
        // Second subscription type is 'web_push' which IS supported → one log persisted
        $household = new Household('Test Household');
        $user = new User($household, 'user@example.com', 'hashed', 'Alice');
        $user->setPushSubscriptions([
            [
                'type' => 'unknown_transport',
                'endpoint' => 'https://example.com/push1',
                'keys' => [
                    'p256dh' => 'key1',
                    'auth' => 'auth1',
                ],
                'device_name' => 'Unknown',
                'last_seen' => '2024-01-01',
            ],
            [
                'type' => 'web_push',
                'endpoint' => 'https://example.com/push2',
                'keys' => [
                    'p256dh' => 'key2',
                    'auth' => 'auth2',
                ],
                'device_name' => 'Desktop',
                'last_seen' => '2024-01-01',
            ],
        ]);

        $habit = new Habit(
            household: $household,
            name: 'Morning Run',
            frequency: HabitFrequency::DAILY,
        );

        // Transport only supports 'web_push', not 'unknown_transport'
        $transport = self::createStub(PushTransportInterface::class);
        $transport->method('supports')->willReturnCallback(
            static fn (string $type): bool => $type === 'web_push',
        );
        $transport->method('send')->willReturn(PushResult::success(201));

        $registry = new TransportRegistry([$transport]);

        $persistCount = 0;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            static fn (string $class): mixed => match ($class) {
                User::class => $user,
                Habit::class => $habit,
                default => null,
            },
        );
        $em->method('persist')->willReturnCallback(
            static function () use (&$persistCount): void {
                ++$persistCount;
            },
        );
        $em->expects(self::once())->method('flush');

        $handler = new NotifyHabitHandler($em, $registry);
        $handler(new NotifyHabitMessage(
            $habit->getId()->toRfc4122(),
            $user->getId()->toRfc4122(),
        ));

        // Only the second subscription (web_push) should have been persisted
        self::assertSame(1, $persistCount, 'Only the second subscription should generate a log (continue skips first)');
    }

    public function testInvokeLogChannelFallsBackToWebPushWhenTypeUnknown(): void
    {
        // 'apns' is a valid NotificationChannel, but 'ntfy' is also valid.
        // Use a type string that is NOT in the NotificationChannel enum to trigger the `?? WEB_PUSH` fallback
        [$em, $user, $habit] = $this->buildEmWithUserAndHabit([
            [
                'type' => 'completely_unknown_type',
                'endpoint' => 'https://example.com/push',
                'keys' => [
                    'p256dh' => 'key',
                    'auth' => 'auth',
                ],
                'device_name' => 'Device',
                'last_seen' => '2024-01-01',
            ],
        ]);

        $transport = self::createStub(PushTransportInterface::class);
        $transport->method('supports')->willReturn(true);
        $transport->method('send')->willReturn(PushResult::success(201));

        $registry = new TransportRegistry([$transport]);

        $persistedLog = null;
        $em->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persistedLog): void {
                if ($entity instanceof NotificationLog) {
                    $persistedLog = $entity;
                }
            },
        );

        $handler = new NotifyHabitHandler($em, $registry);
        $handler(new NotifyHabitMessage(
            $habit->getId()->toRfc4122(),
            $user->getId()->toRfc4122(),
        ));

        self::assertInstanceOf(NotificationLog::class, $persistedLog);
        self::assertSame(NotificationChannel::WEB_PUSH, $persistedLog->getChannel());
    }

    public function testInvokeLogStatusIsSentOnSuccessfulSend(): void
    {
        [$em, $user, $habit] = $this->buildEmWithUserAndHabit([
            [
                'type' => 'web_push',
                'endpoint' => 'https://example.com/push',
                'keys' => [
                    'p256dh' => 'key',
                    'auth' => 'auth',
                ],
                'device_name' => 'Desktop',
                'last_seen' => '2024-01-01',
            ],
        ]);

        $transport = self::createStub(PushTransportInterface::class);
        $transport->method('supports')->willReturn(true);
        $transport->method('send')->willReturn(PushResult::success(201));

        $registry = new TransportRegistry([$transport]);

        $persistedLog = null;
        $em->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persistedLog): void {
                if ($entity instanceof NotificationLog) {
                    $persistedLog = $entity;
                }
            },
        );

        $handler = new NotifyHabitHandler($em, $registry);
        $handler(new NotifyHabitMessage(
            $habit->getId()->toRfc4122(),
            $user->getId()->toRfc4122(),
        ));

        self::assertInstanceOf(NotificationLog::class, $persistedLog);
        self::assertSame(NotificationStatus::SENT, $persistedLog->getStatus());
    }

    public function testInvokeLogStatusIsFailedOnFailedSend(): void
    {
        [$em, $user, $habit] = $this->buildEmWithUserAndHabit([
            [
                'type' => 'web_push',
                'endpoint' => 'https://example.com/push',
                'keys' => [
                    'p256dh' => 'key',
                    'auth' => 'auth',
                ],
                'device_name' => 'Desktop',
                'last_seen' => '2024-01-01',
            ],
        ]);

        $transport = self::createStub(PushTransportInterface::class);
        $transport->method('supports')->willReturn(true);
        $transport->method('send')->willReturn(PushResult::failure(500, 'Internal Error'));

        $registry = new TransportRegistry([$transport]);

        $persistedLog = null;
        $em->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persistedLog): void {
                if ($entity instanceof NotificationLog) {
                    $persistedLog = $entity;
                }
            },
        );

        $handler = new NotifyHabitHandler($em, $registry);
        $handler(new NotifyHabitMessage(
            $habit->getId()->toRfc4122(),
            $user->getId()->toRfc4122(),
        ));

        self::assertInstanceOf(NotificationLog::class, $persistedLog);
        self::assertSame(NotificationStatus::FAILED, $persistedLog->getStatus());
    }

    public function testInvokeRemovesSubscriptionWithReindexedKeys(): void
    {
        // Two subscriptions: remove the first, second must be at index 0 afterwards
        $sub1 = [
            'type' => 'web_push',
            'endpoint' => 'https://example.com/push1',
            'keys' => [
                'p256dh' => 'key1',
                'auth' => 'auth1',
            ],
            'device_name' => 'Phone',
            'last_seen' => '2024-01-01',
        ];
        $sub2 = [
            'type' => 'web_push',
            'endpoint' => 'https://example.com/push2',
            'keys' => [
                'p256dh' => 'key2',
                'auth' => 'auth2',
            ],
            'device_name' => 'Desktop',
            'last_seen' => '2024-01-01',
        ];

        [$em, $user, $habit] = $this->buildEmWithUserAndHabit([$sub1, $sub2]);

        // First send returns shouldRemove=true, second returns success
        $callCount = 0;
        $transport = self::createStub(PushTransportInterface::class);
        $transport->method('supports')->willReturn(true);
        $transport->method('send')->willReturnCallback(
            static function () use (&$callCount): PushResult {
                ++$callCount;

                return $callCount === 1
                    ? PushResult::failure(410, 'Gone', shouldRemove: true)
                    : PushResult::success(201);
            },
        );

        $registry = new TransportRegistry([$transport]);
        $em->method('persist');

        $handler = new NotifyHabitHandler($em, $registry);
        $handler(new NotifyHabitMessage(
            $habit->getId()->toRfc4122(),
            $user->getId()->toRfc4122(),
        ));

        $remaining = $user->getPushSubscriptions();
        self::assertIsArray($remaining);
        self::assertCount(1, $remaining);
        // array_values reindex means index must be 0, not 1
        self::assertArrayHasKey(0, $remaining);
        $first = $remaining[0];
        self::assertIsArray($first);
        self::assertArrayHasKey('endpoint', $first);
        self::assertSame('https://example.com/push2', $first['endpoint']);
    }

    /**
     * @param array<int, array{type: string, endpoint: string, keys: array{p256dh: string, auth: string}, device_name: string, last_seen: string}> $subscriptions
     * @return array{0: EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject, 1: User, 2: Habit}
     */
    private function buildEmWithUserAndHabit(array $subscriptions): array
    {
        $household = new Household('Test Household');
        $user = new User($household, 'user@example.com', 'hashed', 'Alice');
        $user->setPushSubscriptions($subscriptions);

        $habit = new Habit(
            household: $household,
            name: 'Morning Run',
            frequency: HabitFrequency::DAILY,
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            static fn (string $class): mixed => match ($class) {
                User::class => $user,
                Habit::class => $habit,
                default => null,
            },
        );

        return [$em, $user, $habit];
    }
}
