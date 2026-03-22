<?php

declare(strict_types=1);

namespace Tests\Integration\Habit;

use App\Auth\Entity\User;
use App\Habit\Command\LearnTimeWindowsCommand;
use App\Habit\Entity\Habit;
use App\Habit\Entity\HabitLog;
use App\Habit\Enum\HabitFrequency;
use App\Habit\Enum\HabitLogSource;
use App\Habit\Enum\TimeWindowMode;
use App\Household\Entity\Household;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(LearnTimeWindowsCommand::class)]
final class LearnTimeWindowsCommandTest extends KernelTestCase
{
    public function testCommandUpdatesTimeWindowForAutoModeHabitWithSufficientLogs(): void
    {
        $kernel = self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $household = new Household('Learn Test Household ' . uniqid('', true));
        $em->persist($household);

        $user = new User(
            household: $household,
            email: uniqid('learn_', true) . '@example.com',
            password: password_hash('password123', \PASSWORD_BCRYPT),
            displayName: 'Learn Test User',
            timezone: 'UTC',
        );
        $em->persist($user);

        $habit = new Habit(
            household: $household,
            name: 'Learn Test Habit ' . uniqid('', true),
            frequency: HabitFrequency::DAILY,
            timeWindowMode: TimeWindowMode::AUTO,
        );
        $em->persist($habit);
        $em->flush();

        // Create 14 logs around 07:30 UTC spread over last 3 weeks,
        // all on weekdays to guarantee learnWithSplit has >= 7 weekday data points
        $tz = new \DateTimeZone('UTC');
        $logsCreated = 0;
        $daysBack = 0;
        while ($logsCreated < 14) {
            ++$daysBack;
            $candidate = new \DateTimeImmutable(\sprintf('-%d days 07:30:00', $daysBack), $tz);
            $dow = (int) $candidate->format('N'); // 1=Mon, 7=Sun
            if ($dow >= 6) {
                continue; // skip weekend
            }
            $log = new HabitLog(
                habit: $habit,
                user: $user,
                loggedAt: $candidate,
                source: HabitLogSource::MANUAL,
            );
            $em->persist($log);
            ++$logsCreated;
        }
        $em->flush();

        // Verify the habit was persisted with AUTO mode before running command
        $habitId = $habit->getId();
        $em->clear();
        $reloadedBefore = $em->find(Habit::class, $habitId);
        assert($reloadedBefore instanceof Habit);
        self::assertSame(TimeWindowMode::AUTO, $reloadedBefore->getTimeWindowMode(), 'Habit should be in AUTO mode before command runs');

        $application = new Application($kernel);
        $command = $application->find('app:learn-timewindows');
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Updated ', $tester->getDisplay());
        self::assertStringContainsString('habit time windows', $tester->getDisplay());

        $em->clear();
        $reloaded = $em->find(Habit::class, $habitId);
        assert($reloaded instanceof Habit);

        self::assertNotNull($reloaded->getTimeWindowStart(), 'Expected time_window_start to be set after learning');
        self::assertNotNull($reloaded->getTimeWindowEnd(), 'Expected time_window_end to be set after learning');
        self::assertSame(TimeWindowMode::AUTO, $reloaded->getTimeWindowMode());
    }

    public function testCommandSkipsManualModeHabits(): void
    {
        $kernel = self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $household = new Household('Skip Manual Household ' . uniqid('', true));
        $em->persist($household);

        $user = new User(
            household: $household,
            email: uniqid('skip_manual_', true) . '@example.com',
            password: password_hash('password123', \PASSWORD_BCRYPT),
            displayName: 'Skip Manual User',
            timezone: 'UTC',
        );
        $em->persist($user);

        $manualStart = new \DateTimeImmutable('08:00');
        $manualEnd = new \DateTimeImmutable('09:00');

        $habit = new Habit(
            household: $household,
            name: 'Manual Habit ' . uniqid('', true),
            frequency: HabitFrequency::DAILY,
            timeWindowStart: $manualStart,
            timeWindowEnd: $manualEnd,
            timeWindowMode: TimeWindowMode::MANUAL,
        );
        $em->persist($habit);
        $em->flush();

        // Create logs that would suggest a different window (evening logs)
        $tz2 = new \DateTimeZone('UTC');
        $eveningLogsCreated = 0;
        $eveningDaysBack = 0;
        while ($eveningLogsCreated < 10) {
            ++$eveningDaysBack;
            $candidate = new \DateTimeImmutable(\sprintf('-%d days 20:00:00', $eveningDaysBack), $tz2);
            $dow = (int) $candidate->format('N');
            if ($dow >= 6) {
                continue;
            }
            $log = new HabitLog(
                habit: $habit,
                user: $user,
                loggedAt: $candidate,
                source: HabitLogSource::MANUAL,
            );
            $em->persist($log);
            ++$eveningLogsCreated;
        }
        $em->flush();

        $manualHabitId = $habit->getId();
        $application = new Application($kernel);
        $command = $application->find('app:learn-timewindows');
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());

        $em->clear();
        $reloaded = $em->find(Habit::class, $manualHabitId);
        assert($reloaded instanceof Habit);

        // Manual window should remain unchanged
        self::assertSame(TimeWindowMode::MANUAL, $reloaded->getTimeWindowMode());
        self::assertSame('08:00', $reloaded->getTimeWindowStart()?->format('H:i'));
        self::assertSame('09:00', $reloaded->getTimeWindowEnd()?->format('H:i'));
    }

    public function testCommandOutputsZeroWhenNoAutoHabits(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $command = $application->find('app:learn-timewindows');
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Updated', $tester->getDisplay());
    }
}
