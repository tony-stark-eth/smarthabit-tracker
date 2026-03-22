<?php

declare(strict_types=1);

namespace App\Notification\Command;

use App\Auth\Entity\User;
use App\Habit\Entity\Habit;
use App\Habit\Entity\HabitLog;
use App\Notification\Entity\NotificationLog;
use App\Notification\Enum\NotificationStatus;
use App\Notification\Message\NotifyHabitMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:check-habits', description: 'Check habits and dispatch push notifications')]
final class CheckHabitsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<Habit> $habits */
        $habits = $this->em->createQueryBuilder()
            ->select('h')
            ->from(Habit::class, 'h')
            ->where('h.deletedAt IS NULL')
            ->andWhere('h.timeWindowStart IS NOT NULL')
            ->andWhere('h.timeWindowEnd IS NOT NULL')
            ->getQuery()
            ->getResult();

        $dispatched = 0;

        foreach ($habits as $habit) {
            $dispatched += $this->processHabit($habit);
        }

        $output->writeln(\sprintf('Dispatched %d notifications', $dispatched));

        return Command::SUCCESS;
    }

    private function processHabit(Habit $habit): int
    {
        /** @var list<User> $users */
        $users = $this->em->getRepository(User::class)->findBy([
            'household' => $habit->getHousehold(),
        ]);

        $dispatched = 0;

        foreach ($users as $user) {
            if ($this->shouldNotify($habit, $user)) {
                $this->bus->dispatch(new NotifyHabitMessage(
                    habitId: $habit->getId()->toRfc4122(),
                    userId: $user->getId()->toRfc4122(),
                ));
                ++$dispatched;
            }
        }

        return $dispatched;
    }

    private function shouldNotify(Habit $habit, User $user): bool
    {
        if ($user->getDeletedAt() !== null) {
            return false;
        }

        $pushSubscriptions = $user->getPushSubscriptions();
        if ($pushSubscriptions === null || $pushSubscriptions === []) {
            return false;
        }

        if (! $this->isInTimeWindow($habit, $user)) {
            return false;
        }

        if ($this->isLoggedToday($habit, $user)) {
            return false;
        }

        return ! $this->wasNotifiedToday($habit, $user);
    }

    private function isInTimeWindow(Habit $habit, User $user): bool
    {
        $tz = new \DateTimeZone($user->getTimezone());
        $now = new \DateTimeImmutable('now', $tz);
        $currentTime = (int) $now->format('Hi'); // e.g. 0732

        $start = $habit->getTimeWindowStart();
        $end = $habit->getTimeWindowEnd();
        if (! $start instanceof \DateTimeImmutable || ! $end instanceof \DateTimeImmutable) {
            return false;
        }

        $startTime = (int) $start->format('Hi');
        $endTime = (int) $end->format('Hi');

        return $currentTime >= $startTime && $currentTime <= $endTime;
    }

    private function isLoggedToday(Habit $habit, User $user): bool
    {
        $tz = new \DateTimeZone($user->getTimezone());
        $todayStart = new \DateTimeImmutable('now', $tz)->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'));
        $todayEnd = new \DateTimeImmutable('now', $tz)->setTime(23, 59, 59)->setTimezone(new \DateTimeZone('UTC'));

        $count = $this->em->createQueryBuilder()
            ->select('COUNT(hl.id)')
            ->from(HabitLog::class, 'hl')
            ->where('hl.habit = :habit')
            ->andWhere('hl.loggedAt BETWEEN :start AND :end')
            ->setParameter('habit', $habit)
            ->setParameter('start', $todayStart)
            ->setParameter('end', $todayEnd)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    private function wasNotifiedToday(Habit $habit, User $user): bool
    {
        $tz = new \DateTimeZone($user->getTimezone());
        $todayStart = new \DateTimeImmutable('now', $tz)->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'));

        $count = $this->em->createQueryBuilder()
            ->select('COUNT(nl.id)')
            ->from(NotificationLog::class, 'nl')
            ->where('nl.habit = :habit')
            ->andWhere('nl.user = :user')
            ->andWhere('nl.sentAt >= :start')
            ->andWhere('nl.status != :failed')
            ->setParameter('habit', $habit)
            ->setParameter('user', $user)
            ->setParameter('start', $todayStart)
            ->setParameter('failed', NotificationStatus::FAILED->value)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
