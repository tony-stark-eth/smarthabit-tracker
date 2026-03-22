<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use App\Auth\Entity\User;
use App\Habit\Entity\Habit;
use App\Habit\Enum\HabitFrequency;
use App\Household\Entity\Household;
use App\Notification\Command\CheckHabitsCommand;
use App\Notification\Message\NotifyHabitMessage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[CoversClass(CheckHabitsCommand::class)]
final class CheckHabitsCommandTest extends KernelTestCase
{
    public function testCommandDispatchesNotifyHabitMessageForEligibleHabit(): void
    {
        $kernel = self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        // Create household, user with push subscription, and habit with wide time window
        $household = new Household('Test Household ' . uniqid('', true));
        $em->persist($household);

        $email = uniqid('cmd_check_', true) . '@example.com';
        $user = new User(
            household: $household,
            email: $email,
            password: password_hash('password123', \PASSWORD_BCRYPT),
            displayName: 'Test User',
            timezone: 'UTC',
        );
        $user->setPushSubscriptions([
            [
                'type' => 'web_push',
                'endpoint' => 'https://push.example.com/sub/cmd-test-' . uniqid('', true),
                'keys' => [
                    'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlTiKWhk1jIwhte_0t6f5gJW-eOb3xYYLK',
                    'auth' => 'tBHItJI5svbpez7KI4CCXg',
                ],
                'device_name' => 'Test Browser',
                'last_seen' => new \DateTimeImmutable()->format(\DateTimeInterface::ATOM),
            ],
        ]);
        $em->persist($user);

        // Wide time window covering the full day (00:00 – 23:59)
        $habit = new Habit(
            household: $household,
            name: 'Test Habit ' . uniqid('', true),
            frequency: HabitFrequency::DAILY,
            timeWindowStart: new \DateTimeImmutable('00:00'),
            timeWindowEnd: new \DateTimeImmutable('23:59'),
        );
        $em->persist($habit);
        $em->flush();

        $application = new Application($kernel);
        $command = $application->find('app:check-habits');
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());

        $transport = self::getContainer()->get('messenger.transport.async');
        assert($transport instanceof InMemoryTransport);

        $envelopes = $transport->getSent();
        self::assertNotEmpty($envelopes, 'Expected at least one message to be dispatched.');

        $habitId = $habit->getId()->toRfc4122();
        $userId = $user->getId()->toRfc4122();

        $found = false;
        foreach ($envelopes as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof NotifyHabitMessage
                && $message->habitId === $habitId
                && $message->userId === $userId
            ) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Expected NotifyHabitMessage was not dispatched for the test habit/user pair.');
    }

    public function testCommandOutputsDispatchedCount(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $command = $application->find('app:check-habits');
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Dispatched', $tester->getDisplay());
    }
}
