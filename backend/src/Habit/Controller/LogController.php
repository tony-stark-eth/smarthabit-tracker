<?php

declare(strict_types=1);

namespace App\Habit\Controller;

use App\Auth\Entity\User;
use App\Habit\Entity\Habit;
use App\Habit\Entity\HabitLog;
use App\Habit\Enum\HabitLogSource;
use App\Shared\Security\HouseholdVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class LogController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/v1/habits/{id}/log', name: 'api_habit_log_create', methods: ['POST'])]
    public function create(string $id, Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (! $user instanceof User) {
            return $this->unauthorizedResponse();
        }

        $habit = $this->entityManager->find(Habit::class, Uuid::fromString($id));

        if (! $habit instanceof Habit) {
            return new JsonResponse([
                'error' => 'Habit not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        if (! $this->isGranted(HouseholdVoter::EDIT, $habit)) {
            return new JsonResponse([
                'error' => 'Access denied.',
            ], Response::HTTP_FORBIDDEN);
        }

        $log = $this->buildLog($habit, $user, $request);

        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $log->getId()->toRfc4122(),
            'habit_id' => $habit->getId()->toRfc4122(),
            'logged_at' => $log->getLoggedAt()->format(\DateTimeInterface::ATOM),
            'source' => $log->getSource()->value,
            'note' => $log->getNote(),
            'created_at' => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/habits/{id}/log/{logId}', name: 'api_habit_log_delete', methods: ['DELETE'])]
    public function delete(string $id, string $logId): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (! $user instanceof User) {
            return $this->unauthorizedResponse();
        }

        $log = $this->entityManager->find(HabitLog::class, Uuid::fromString($logId));

        if (! $log instanceof HabitLog) {
            return new JsonResponse([
                'error' => 'Log not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        if (! $log->getUser()->getId()->equals($user->getId())) {
            return new JsonResponse([
                'error' => 'Access denied.',
            ], Response::HTTP_FORBIDDEN);
        }

        $this->entityManager->remove($log);
        $this->entityManager->flush();

        return new JsonResponse([
            'message' => 'Log deleted.',
        ]);
    }

    #[Route('/api/v1/habits/{id}/history', name: 'api_habit_history', methods: ['GET'])]
    public function history(string $id, Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (! $user instanceof User) {
            return $this->unauthorizedResponse();
        }

        $habit = $this->entityManager->find(Habit::class, Uuid::fromString($id));

        if (! $habit instanceof Habit) {
            return new JsonResponse([
                'error' => 'Habit not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        if (! $this->isGranted(HouseholdVoter::VIEW, $habit)) {
            return new JsonResponse([
                'error' => 'Access denied.',
            ], Response::HTTP_FORBIDDEN);
        }

        $page = max(1, (int) $request->query->get('page', '1'));
        $limit = max(1, min(100, (int) $request->query->get('limit', '20')));
        $offset = ($page - 1) * $limit;

        $qb = $this->entityManager->createQueryBuilder();

        $total = (int) $qb
            ->select('COUNT(hl.id)')
            ->from(HabitLog::class, 'hl')
            ->where('hl.habit = :habit')
            ->setParameter('habit', $habit)
            ->getQuery()
            ->getSingleScalarResult();

        $logs = $this->entityManager->createQueryBuilder()
            ->select('hl', 'u')
            ->from(HabitLog::class, 'hl')
            ->join('hl.user', 'u')
            ->where('hl.habit = :habit')
            ->setParameter('habit', $habit)
            ->orderBy('hl.loggedAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        /** @var list<HabitLog> $logs */
        $data = array_map(static fn (HabitLog $log): array => [
            'id' => $log->getId()->toRfc4122(),
            'logged_at' => $log->getLoggedAt()->format(\DateTimeInterface::ATOM),
            'user_display_name' => $log->getUser()->getDisplayName(),
            'note' => $log->getNote(),
        ], $logs);

        return new JsonResponse([
            'data' => $data,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
        ]);
    }

    private function buildLog(Habit $habit, User $user, Request $request): HabitLog
    {
        try {
            /** @var array<string, mixed> $data */
            $data = $request->getContent() !== '' ? $request->toArray() : [];
        } catch (\Throwable) {
            $data = [];
        }

        $sourceValue = isset($data['source']) && is_string($data['source']) ? $data['source'] : 'manual';
        $source = HabitLogSource::tryFrom($sourceValue) ?? HabitLogSource::MANUAL;
        $note = isset($data['note']) && is_string($data['note']) ? $data['note'] : null;

        return new HabitLog($habit, $user, new \DateTimeImmutable(), $source, $note);
    }

    private function getAuthenticatedUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    private function unauthorizedResponse(): JsonResponse
    {
        return new JsonResponse([
            'error' => 'Unauthorized.',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
