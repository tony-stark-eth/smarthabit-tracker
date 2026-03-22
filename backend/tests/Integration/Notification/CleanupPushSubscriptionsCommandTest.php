<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use App\Auth\Entity\User;
use App\Household\Entity\Household;
use App\Notification\Command\CleanupPushSubscriptionsCommand;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CleanupPushSubscriptionsCommand::class)]
final class CleanupPushSubscriptionsCommandTest extends KernelTestCase
{
    public function testCommandRemovesStaleSubscriptions(): void
    {
        $kernel = self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $household = new Household('Cleanup Test Household ' . uniqid('', true));
        $em->persist($household);

        $email = uniqid('cleanup_', true) . '@example.com';
        $user = new User(
            household: $household,
            email: $email,
            password: password_hash('password123', \PASSWORD_BCRYPT),
            displayName: 'Cleanup User',
            timezone: 'UTC',
        );

        $staleDate = new \DateTimeImmutable('-60 days')->format(\DateTimeInterface::ATOM);
        $freshDate = new \DateTimeImmutable('-1 day')->format(\DateTimeInterface::ATOM);

        $user->setPushSubscriptions([
            [
                'type' => 'web_push',
                'endpoint' => 'https://push.example.com/sub/stale',
                'keys' => [
                    'p256dh' => 'key',
                    'auth' => 'auth',
                ],
                'device_name' => 'Stale Device',
                'last_seen' => $staleDate,
            ],
            [
                'type' => 'web_push',
                'endpoint' => 'https://push.example.com/sub/fresh',
                'keys' => [
                    'p256dh' => 'key',
                    'auth' => 'auth',
                ],
                'device_name' => 'Fresh Device',
                'last_seen' => $freshDate,
            ],
        ]);
        $em->persist($user);
        $em->flush();

        $application = new Application($kernel);
        $command = $application->find('app:cleanup-push-subscriptions');
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Removed 1 stale subscriptions', $tester->getDisplay());

        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy([
            'email' => $email,
        ]);
        assert($user instanceof User);

        $subscriptions = $user->getPushSubscriptions();
        self::assertNotNull($subscriptions);
        self::assertCount(1, $subscriptions);
        self::assertArrayHasKey(0, $subscriptions);

        /** @var array{endpoint: string} $first */
        $first = $subscriptions[0];
        self::assertSame('https://push.example.com/sub/fresh', $first['endpoint']);
    }

    public function testCommandKeepsSubscriptionsWithNoLastSeen(): void
    {
        $kernel = self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $household = new Household('No LastSeen Household ' . uniqid('', true));
        $em->persist($household);

        $email = uniqid('nolastseen_', true) . '@example.com';
        $user = new User(
            household: $household,
            email: $email,
            password: password_hash('password123', \PASSWORD_BCRYPT),
            displayName: 'No LastSeen User',
            timezone: 'UTC',
        );

        /** @var array<int, array{endpoint: string, keys: array<string, string>, device_name: string, last_seen: string, type: string}> $subs */
        $subs = [
            [
                'type' => 'web_push',
                'endpoint' => 'https://push.example.com/sub/no-last-seen',
                'keys' => [
                    'p256dh' => 'key',
                    'auth' => 'auth',
                ],
                'device_name' => 'Old Device',
                'last_seen' => '', // empty string — no meaningful last_seen, acts like missing
            ],
        ];
        $user->setPushSubscriptions($subs);
        $em->persist($user);
        $em->flush();

        $application = new Application($kernel);
        $command = $application->find('app:cleanup-push-subscriptions');
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());

        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy([
            'email' => $email,
        ]);
        assert($user instanceof User);

        $subscriptions = $user->getPushSubscriptions();
        self::assertNotNull($subscriptions);
        self::assertCount(1, $subscriptions);
    }
}
