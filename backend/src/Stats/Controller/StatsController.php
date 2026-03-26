<?php

declare(strict_types=1);

namespace App\Stats\Controller;

use App\Habit\Entity\Habit;
use App\Habit\Entity\HabitLog;
use App\Habit\Repository\HabitRepository;
use App\Shared\Contract\HouseholdAwareUserInterface;
use App\Shared\Security\HouseholdVoter;
use App\Stats\Service\StatsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StatsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HabitRepository $habitRepository,
        private readonly StatsService $statsService,
    ) {
    }

    #[Route('/api/v1/habits/{id}/stats', name: 'api_habit_stats', methods: ['GET'])]
    public function habitStats(string $id): JsonResponse
    {
        $user = $this->getUser();
        if (! $user instanceof HouseholdAwareUserInterface) {
            return new JsonResponse([
                'error' => 'Unauthorized.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $habit = $this->entityManager->getRepository(Habit::class)->find($id);
        if (! $habit instanceof Habit) {
            return new JsonResponse([
                'error' => 'Habit not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(HouseholdVoter::VIEW, $habit);

        $tz = new \DateTimeZone($user->getTimezone());
        $now = \Carbon\CarbonImmutable::now($tz)->toDateTimeImmutable();
        $since90dUtc = $now->modify('-90 days')->setTimezone(new \DateTimeZone('UTC'));

        /** @var list<HabitLog> $logs */
        $logs = $this->entityManager->createQueryBuilder()
            ->select('hl')
            ->from(HabitLog::class, 'hl')
            ->where('hl.habit = :habit')
            ->andWhere('hl.loggedAt >= :since')
            ->setParameter('habit', $habit)
            ->setParameter('since', $since90dUtc)
            ->orderBy('hl.loggedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $logDates = array_map(
            fn (HabitLog $log): \DateTimeImmutable => $log->getLoggedAt()->setTimezone($tz),
            $logs,
        );

        $currentStreak = $this->statsService->currentStreak($logDates);
        $longestStreak = $this->statsService->longestStreak($logDates);

        $cutoff30d = $now->modify('-30 days')->setTimezone(new \DateTimeZone('UTC'));
        $cutoff60d = $now->modify('-60 days')->setTimezone(new \DateTimeZone('UTC'));

        $logs30d = array_filter($logs, fn (HabitLog $l): bool => $l->getLoggedAt() >= $cutoff30d);
        $logs60d30d = array_filter(
            $logs,
            fn (HabitLog $l): bool => $l->getLoggedAt() >= $cutoff60d && $l->getLoggedAt() < $cutoff30d,
        );

        $dates30d = array_values(array_map(
            fn (HabitLog $log): \DateTimeImmutable => $log->getLoggedAt()->setTimezone($tz),
            $logs30d,
        ));
        $datesPrev30d = array_values(array_map(
            fn (HabitLog $log): \DateTimeImmutable => $log->getLoggedAt()->setTimezone($tz),
            $logs60d30d,
        ));

        $completionRate30d = $this->statsService->completionRate($dates30d, 30);
        $completionRatePrev30d = $this->statsService->completionRate($datesPrev30d, 30);
        $trend = $this->statsService->rateTrend($completionRate30d, $completionRatePrev30d);

        $avgTime = $this->statsService->averageCompletionTime($logDates);
        $avgTimeFormatted = $avgTime !== null
            ? sprintf('%02d:%02d', intdiv($avgTime, 60), $avgTime % 60)
            : null;

        // Compute 4 weekly buckets (Mon–Sun), starting 4 weeks ago
        $weeklyBuckets = [];
        for ($i = 3; $i >= 0; $i--) {
            $weekStart = $now->modify(sprintf('-%d weeks', $i))->modify('Monday this week')->setTime(0, 0, 0);
            $weekEnd = $weekStart->modify('+7 days');

            $daysInBucket = $weekEnd > $now ? (int) $now->diff($weekStart)->days : 7;
            $daysInBucket = max(1, $daysInBucket);

            $bucketDates = array_values(array_filter(
                $dates30d,
                fn (\DateTimeImmutable $d): bool => $d >= $weekStart && $d < $weekEnd,
            ));

            $weeklyBuckets[] = [
                'week_start' => $weekStart->format('Y-m-d'),
                'completion_rate' => $this->statsService->completionRate($bucketDates, $daysInBucket),
            ];
        }

        return new JsonResponse([
            'habit_id' => $habit->getId()->toRfc4122(),
            'habit_name' => $habit->getName(),
            'habit_icon' => $habit->getIcon(),
            'current_streak' => $currentStreak,
            'longest_streak' => $longestStreak,
            'completion_rate_30d' => $completionRate30d,
            'completion_rate_prev_30d' => $completionRatePrev30d,
            'average_completion_time' => $avgTimeFormatted,
            'trend' => $trend,
            'weekly_buckets' => $weeklyBuckets,
        ]);
    }

    #[Route('/api/v1/stats/household', name: 'api_stats_household', methods: ['GET'])]
    public function householdStats(): JsonResponse
    {
        $user = $this->getUser();
        if (! $user instanceof HouseholdAwareUserInterface) {
            return new JsonResponse([
                'error' => 'Unauthorized.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $household = $user->getHousehold();
        $this->denyAccessUnlessGranted(HouseholdVoter::VIEW, $household);

        $habits = $this->habitRepository->findActiveByHousehold($household);

        if ($habits === []) {
            return new JsonResponse([
                'overall_completion_rate' => 0.0,
                'weekday_heatmap' => array_fill(1, 7, 0),
                'time_heatmap' => array_fill(0, 24, 0),
                'habits' => [],
            ]);
        }

        $tz = new \DateTimeZone($user->getTimezone());
        $now = \Carbon\CarbonImmutable::now($tz)->toDateTimeImmutable();
        $since90dUtc = $now->modify('-90 days')->setTimezone(new \DateTimeZone('UTC'));
        $since30dUtc = $now->modify('-30 days')->setTimezone(new \DateTimeZone('UTC'));

        /** @var list<HabitLog> $allLogs */
        $allLogs = $this->entityManager->createQueryBuilder()
            ->select('hl')
            ->from(HabitLog::class, 'hl')
            ->where('hl.habit IN (:habits)')
            ->andWhere('hl.loggedAt >= :since')
            ->setParameter('habits', $habits)
            ->setParameter('since', $since90dUtc)
            ->orderBy('hl.loggedAt', 'DESC')
            ->getQuery()
            ->getResult();

        // Build per-habit stats
        $logsByHabit = [];
        foreach ($allLogs as $log) {
            $habitId = $log->getHabit()->getId()->toRfc4122();
            $logsByHabit[$habitId][] = $log;
        }

        $habitStats = [];
        foreach ($habits as $habit) {
            $habitId = $habit->getId()->toRfc4122();
            $habitLogs = $logsByHabit[$habitId] ?? [];

            $dates30d = array_values(array_map(
                fn (HabitLog $log): \DateTimeImmutable => $log->getLoggedAt()->setTimezone($tz),
                array_filter($habitLogs, fn (HabitLog $l): bool => $l->getLoggedAt() >= $since30dUtc),
            ));

            $allDates = array_map(
                fn (HabitLog $log): \DateTimeImmutable => $log->getLoggedAt()->setTimezone($tz),
                $habitLogs,
            );

            $habitStats[] = [
                'id' => $habitId,
                'name' => $habit->getName(),
                'icon' => $habit->getIcon(),
                'completion_rate_30d' => $this->statsService->completionRate($dates30d, 30),
                'current_streak' => $this->statsService->currentStreak(array_values($allDates)),
            ];
        }

        // Overall stats from all 30d logs
        $allDates30d = array_values(array_map(
            fn (HabitLog $log): \DateTimeImmutable => $log->getLoggedAt()->setTimezone($tz),
            array_filter($allLogs, fn (HabitLog $l): bool => $l->getLoggedAt() >= $since30dUtc),
        ));

        $allDates90d = array_values(array_map(
            fn (HabitLog $log): \DateTimeImmutable => $log->getLoggedAt()->setTimezone($tz),
            $allLogs,
        ));

        $overallRate = $this->statsService->completionRate($allDates30d, 30 * \count($habits));
        $weekdayHeatmap = $this->statsService->weekdayHeatmap($allDates90d);
        $timeHeatmap = $this->statsService->timeHeatmap($allDates90d);

        return new JsonResponse([
            'overall_completion_rate' => $overallRate,
            'weekday_heatmap' => $weekdayHeatmap,
            'time_heatmap' => $timeHeatmap,
            'habits' => $habitStats,
        ]);
    }
}
