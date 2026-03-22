<?php

declare(strict_types=1);

namespace App\Habit\Controller;

use App\Auth\Entity\User;
use App\Habit\Entity\Habit;
use App\Habit\Entity\HabitLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/v1/dashboard', name: 'api_dashboard', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (! $user instanceof User) {
            return new JsonResponse([
                'error' => 'Unauthorized.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        /** @var list<Habit> $habits */
        $habits = $this->findActiveHabits($user);
        $latestLogByHabit = $this->findTodayLogsByHabit($habits, $user->getTimezone());
        [$habitData, $doneCount] = $this->buildHabitData($habits, $latestLogByHabit);

        $total = \count($habits);
        $completionRate = $total > 0 ? round($doneCount / $total, 4) : 0.0;

        return new JsonResponse([
            'household_id' => $user->getHousehold()->getId()->toRfc4122(),
            'habits' => $habitData,
            'summary' => [
                'total' => $total,
                'done' => $doneCount,
                'completion_rate' => $completionRate,
            ],
        ]);
    }

    /**
     * @return list<Habit>
     */
    private function findActiveHabits(User $user): array
    {
        /** @var list<Habit> $result */
        $result = $this->entityManager->createQueryBuilder()
            ->select('h')
            ->from(Habit::class, 'h')
            ->where('h.household = :household')
            ->andWhere('h.deletedAt IS NULL')
            ->setParameter('household', $user->getHousehold())
            ->orderBy('h.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @param list<Habit> $habits
     * @return array<string, HabitLog>
     */
    private function findTodayLogsByHabit(array $habits, string $timezone): array
    {
        if ($habits === []) {
            return [];
        }

        $tz = new \DateTimeZone($timezone);
        $now = new \DateTimeImmutable('now', $tz);
        $startUtc = $now->setTime(0, 0, 0)->setTimezone(new \DateTimeZone('UTC'));
        $endUtc = $now->setTime(23, 59, 59)->setTimezone(new \DateTimeZone('UTC'));

        /** @var list<HabitLog> $logs */
        $logs = $this->entityManager->createQueryBuilder()
            ->select('hl', 'u')
            ->from(HabitLog::class, 'hl')
            ->join('hl.user', 'u')
            ->where('hl.habit IN (:habits)')
            ->andWhere('hl.loggedAt BETWEEN :start AND :end')
            ->setParameter('habits', $habits)
            ->setParameter('start', $startUtc)
            ->setParameter('end', $endUtc)
            ->orderBy('hl.loggedAt', 'DESC')
            ->getQuery()
            ->getResult();

        /** @var array<string, HabitLog> $latestByHabit */
        $latestByHabit = [];

        foreach ($logs as $log) {
            $habitId = $log->getHabit()->getId()->toRfc4122();

            if (! isset($latestByHabit[$habitId])) {
                $latestByHabit[$habitId] = $log;
            }
        }

        return $latestByHabit;
    }

    /**
     * @param list<Habit> $habits
     * @param array<string, HabitLog> $latestLogByHabit
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function buildHabitData(array $habits, array $latestLogByHabit): array
    {
        $doneCount = 0;
        $habitData = [];

        foreach ($habits as $habit) {
            $habitId = $habit->getId()->toRfc4122();
            $lastLog = $latestLogByHabit[$habitId] ?? null;
            $isDoneToday = $lastLog instanceof HabitLog;

            if ($isDoneToday) {
                ++$doneCount;
            }

            $habitData[] = [
                'id' => $habitId,
                'name' => $habit->getName(),
                'icon' => $habit->getIcon(),
                'color' => $habit->getColor(),
                'sort_order' => $habit->getSortOrder(),
                'frequency' => $habit->getFrequency()->value,
                'time_window_start' => $habit->getTimeWindowStart()?->format('H:i'),
                'time_window_end' => $habit->getTimeWindowEnd()?->format('H:i'),
                'is_done_today' => $isDoneToday,
                'last_log' => $this->serializeLog($lastLog),
            ];
        }

        return [$habitData, $doneCount];
    }

    /**
     * @return array{id: string, logged_at: string, user_display_name: string, source: string}|null
     */
    private function serializeLog(?HabitLog $log): ?array
    {
        if (! $log instanceof HabitLog) {
            return null;
        }

        return [
            'id' => $log->getId()->toRfc4122(),
            'logged_at' => $log->getLoggedAt()->format(\DateTimeInterface::ATOM),
            'user_display_name' => $log->getUser()->getDisplayName(),
            'source' => $log->getSource()->value,
        ];
    }

    private function getAuthenticatedUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
