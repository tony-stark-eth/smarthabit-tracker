<?php

declare(strict_types=1);

namespace Tests\Unit\Notification\Handler;

use App\Auth\Entity\User;
use App\Habit\Entity\Habit;
use App\Habit\Enum\HabitFrequency;
use App\Household\Entity\Household;
use App\Notification\Entity\NotificationLog;
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
            static function (string $class) use ($user, $habit): mixed {
                return match ($class) {
                    User::class => $user,
                    Habit::class => $habit,
                    default => null,
                };
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
            static function (string $class) use ($user, $habit): mixed {
                return match ($class) {
                    User::class => $user,
                    Habit::class => $habit,
                    default => null,
                };
            },
        );

        return [$em, $user, $habit];
    }
}
