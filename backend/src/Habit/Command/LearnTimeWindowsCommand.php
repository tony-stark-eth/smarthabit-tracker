<?php

declare(strict_types=1);

namespace App\Habit\Command;

use App\Auth\Entity\User;
use App\Habit\Entity\Habit;
use App\Habit\Entity\HabitLog;
use App\Habit\Enum\TimeWindowMode;
use App\Habit\Service\TimeWindowLearner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:learn-timewindows', description: 'Analyze habit logs and update time windows')]
final class LearnTimeWindowsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TimeWindowLearner $learner,
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
            ->andWhere('h.timeWindowMode = :auto')
            ->setParameter('auto', TimeWindowMode::AUTO->value, 'string')
            ->getQuery()
            ->getResult();

        $updated = 0;
        $since = \Carbon\CarbonImmutable::now()->subDays(21);

        foreach ($habits as $habit) {
            /** @var list<HabitLog> $logs */
            $logs = $this->em->createQueryBuilder()
                ->select('hl')
                ->from(HabitLog::class, 'hl')
                ->where('hl.habit = :habit')
                ->andWhere('hl.loggedAt >= :since')
                ->setParameter('habit', $habit)
                ->setParameter('since', $since)
                ->getQuery()
                ->getResult();

            $timestamps = array_map(
                static fn (HabitLog $log): \DateTimeImmutable => $log->getLoggedAt(),
                $logs,
            );

            $user = $this->em->getRepository(User::class)->findOneBy([
                'household' => $habit->getHousehold(),
            ]);

            if (! $user instanceof User) {
                continue;
            }

            $tz = new \DateTimeZone($user->getTimezone());
            $result = $this->learner->learnWithSplit($timestamps, $tz);

            $window = $result['weekday'] ?? $result['weekend'] ?? null;

            if ($window === null) {
                continue;
            }

            $startTime = new \DateTimeImmutable(TimeWindowLearner::minutesToTime($window['start']));
            $endTime = new \DateTimeImmutable(TimeWindowLearner::minutesToTime($window['end']));

            $habit->setTimeWindowStart($startTime);
            $habit->setTimeWindowEnd($endTime);
            $habit->setTimeWindowMode(TimeWindowMode::AUTO);
            ++$updated;
        }

        $this->em->flush();
        $output->writeln(\sprintf('Updated %d habit time windows', $updated));

        return Command::SUCCESS;
    }
}
