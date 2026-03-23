<?php

declare(strict_types=1);

namespace App\Habit\Controller;

use App\Auth\Entity\User;
use App\Habit\Entity\Habit;
use App\Habit\Enum\HabitFrequency;
use App\Habit\Repository\HabitRepository;
use App\Shared\Security\HouseholdVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class HabitController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HabitRepository $habitRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/api/v1/habits', name: 'api_habits_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        if (! $user instanceof User) {
            return $this->unauthorizedResponse();
        }

        $habits = $this->habitRepository->findActiveByHousehold($user->getHousehold());

        return new JsonResponse([
            'habits' => array_map($this->serializeHabit(...), $habits),
        ]);
    }

    #[Route('/api/v1/habits/{id}', name: 'api_habits_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $habit = $this->entityManager->getRepository(Habit::class)->find($id);
        if (! $habit instanceof Habit) {
            return $this->notFoundResponse();
        }

        $this->denyAccessUnlessGranted(HouseholdVoter::VIEW, $habit);

        return new JsonResponse($this->serializeHabit($habit));
    }

    #[Route('/api/v1/habits', name: 'api_habits_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (! $user instanceof User) {
            return $this->unauthorizedResponse();
        }

        $data = $this->parseRequestBody($request);
        if ($data === null) {
            return $this->invalidJsonResponse();
        }

        $validationError = $this->validateCreateData($data);
        if ($validationError instanceof JsonResponse) {
            return $validationError;
        }

        $habit = $this->buildHabitFromData($user, $data);
        $this->entityManager->persist($habit);
        $this->entityManager->flush();

        return new JsonResponse($this->serializeHabit($habit), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/habits/reorder', name: 'api_habits_reorder', methods: ['PATCH'])]
    public function reorder(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (! $user instanceof User) {
            return $this->unauthorizedResponse();
        }

        $data = $this->parseRequestBody($request);
        if ($data === null) {
            return $this->invalidJsonResponse();
        }

        /** @var array<int, array<string, mixed>> $order */
        $order = is_array($data['order'] ?? null) ? $data['order'] : [];
        $this->applyReorder($order);
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
        ]);
    }

    #[Route('/api/v1/habits/{id}', name: 'api_habits_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $habit = $this->entityManager->getRepository(Habit::class)->find($id);
        if (! $habit instanceof Habit) {
            return $this->notFoundResponse();
        }

        $this->denyAccessUnlessGranted(HouseholdVoter::EDIT, $habit);

        $data = $this->parseRequestBody($request);
        if ($data === null) {
            return $this->invalidJsonResponse();
        }

        $validationError = $this->applyUpdates($habit, $data);
        if ($validationError instanceof JsonResponse) {
            return $validationError;
        }

        $this->entityManager->flush();

        return new JsonResponse($this->serializeHabit($habit));
    }

    #[Route('/api/v1/habits/{id}', name: 'api_habits_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $habit = $this->entityManager->getRepository(Habit::class)->find($id);
        if (! $habit instanceof Habit) {
            return $this->notFoundResponse();
        }

        $this->denyAccessUnlessGranted(HouseholdVoter::EDIT, $habit);

        $habit->softDelete();
        $this->entityManager->flush();

        return new JsonResponse([
            'success' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateCreateData(array $data): ?JsonResponse
    {
        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        if ($name === '') {
            return new JsonResponse(
                [
                    'errors' => [
                        'name' => $this->translator->trans('habit.name_required'),
                    ],
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $frequencyValue = is_string($data['frequency'] ?? null) ? $data['frequency'] : '';
        if (HabitFrequency::tryFrom($frequencyValue) === null) {
            return new JsonResponse(
                [
                    'errors' => [
                        'frequency' => $this->translator->trans('habit.invalid_frequency'),
                    ],
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildHabitFromData(User $user, array $data): Habit
    {
        $frequencyValue = is_string($data['frequency'] ?? null) ? $data['frequency'] : 'daily';
        $frequency = HabitFrequency::tryFrom($frequencyValue) ?? HabitFrequency::DAILY;

        return new Habit(
            household: $user->getHousehold(),
            name: trim(is_string($data['name'] ?? null) ? $data['name'] : ''),
            frequency: $frequency,
            description: is_string($data['description'] ?? null) ? $data['description'] : null,
            icon: is_string($data['icon'] ?? null) ? $data['icon'] : null,
            color: is_string($data['color'] ?? null) ? $data['color'] : null,
            sortOrder: is_int($data['sort_order'] ?? null) ? $data['sort_order'] : 0,
            timeWindowStart: $this->parseTimeField($data['time_window_start'] ?? null),
            timeWindowEnd: $this->parseTimeField($data['time_window_end'] ?? null),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $order
     */
    private function applyReorder(array $order): void
    {
        foreach ($order as $item) {
            if (! is_array($item)) {
                continue;
            }

            $this->applyReorderItem($item);
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function applyReorderItem(array $item): void
    {
        $id = is_string($item['id'] ?? null) ? $item['id'] : null;
        if ($id === null) {
            return;
        }

        $habit = $this->entityManager->getRepository(Habit::class)->find($id);
        if (! $habit instanceof Habit) {
            return;
        }

        $this->denyAccessUnlessGranted(HouseholdVoter::EDIT, $habit);
        $sortOrderValue = is_int($item['sort_order'] ?? null) ? $item['sort_order'] : 0;
        $habit->setSortOrder($sortOrderValue);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyUpdates(Habit $habit, array $data): ?JsonResponse
    {
        $nameError = $this->applyNameUpdate($habit, $data);
        if ($nameError instanceof JsonResponse) {
            return $nameError;
        }

        $frequencyError = $this->applyFrequencyUpdate($habit, $data);
        if ($frequencyError instanceof JsonResponse) {
            return $frequencyError;
        }

        $this->applyOptionalFieldUpdates($habit, $data);

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyNameUpdate(Habit $habit, array $data): ?JsonResponse
    {
        if (! isset($data['name'])) {
            return null;
        }

        $name = is_string($data['name']) ? trim($data['name']) : '';
        if ($name === '') {
            return new JsonResponse(
                [
                    'errors' => [
                        'name' => $this->translator->trans('habit.name_required'),
                    ],
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $habit->setName($name);

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyFrequencyUpdate(Habit $habit, array $data): ?JsonResponse
    {
        if (! isset($data['frequency'])) {
            return null;
        }

        $frequencyValue = is_string($data['frequency']) ? $data['frequency'] : '';
        $frequency = HabitFrequency::tryFrom($frequencyValue);
        if (! $frequency instanceof HabitFrequency) {
            return new JsonResponse(
                [
                    'errors' => [
                        'frequency' => $this->translator->trans('habit.invalid_frequency'),
                    ],
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $habit->setFrequency($frequency);

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, \Closure(?string): void> $setters
     */
    private function applyNullableStringFields(array $data, array $setters): void
    {
        foreach ($setters as $key => $setter) {
            if (array_key_exists($key, $data)) {
                $setter(is_string($data[$key]) ? $data[$key] : null);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyOptionalFieldUpdates(Habit $habit, array $data): void
    {
        $this->applyNullableStringFields($data, [
            'description' => $habit->setDescription(...),
            'icon' => $habit->setIcon(...),
            'color' => $habit->setColor(...),
        ]);

        if (isset($data['sort_order']) && is_int($data['sort_order'])) {
            $habit->setSortOrder($data['sort_order']);
        }

        if (array_key_exists('time_window_start', $data) || array_key_exists('time_window_end', $data)) {
            $habit->setTimeWindow(
                $this->parseTimeField($data['time_window_start'] ?? null),
                $this->parseTimeField($data['time_window_end'] ?? null),
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseRequestBody(Request $request): ?array
    {
        try {
            /** @var array<string, mixed> $data */
            $data = $request->toArray();

            return $data;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeHabit(Habit $habit): array
    {
        return [
            'id' => $habit->getId()->toRfc4122(),
            'name' => $habit->getName(),
            'frequency' => $habit->getFrequency()->value,
            'description' => $habit->getDescription(),
            'icon' => $habit->getIcon(),
            'color' => $habit->getColor(),
            'sort_order' => $habit->getSortOrder(),
            'time_window_start' => $habit->getTimeWindowStart()?->format('H:i:s'),
            'time_window_end' => $habit->getTimeWindowEnd()?->format('H:i:s'),
            'time_window_mode' => $habit->getTimeWindowMode()->value,
            'created_at' => $habit->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $habit->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function parseTimeField(mixed $value): ?\DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('H:i:s', $value);
        if ($parsed === false) {
            $parsed = \DateTimeImmutable::createFromFormat('H:i', $value);
        }

        return $parsed !== false ? $parsed : null;
    }

    private function unauthorizedResponse(): JsonResponse
    {
        return new JsonResponse(
            [
                'error' => $this->translator->trans('auth.unauthorized'),
            ],
            Response::HTTP_UNAUTHORIZED,
        );
    }

    private function invalidJsonResponse(): JsonResponse
    {
        return new JsonResponse(
            [
                'error' => $this->translator->trans('auth.invalid_json'),
            ],
            Response::HTTP_BAD_REQUEST,
        );
    }

    private function notFoundResponse(): JsonResponse
    {
        return new JsonResponse(
            [
                'error' => $this->translator->trans('habit.not_found'),
            ],
            Response::HTTP_NOT_FOUND,
        );
    }
}
