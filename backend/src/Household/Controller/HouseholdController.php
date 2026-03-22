<?php

declare(strict_types=1);

namespace App\Household\Controller;

use App\Auth\Entity\User;
use App\Household\Entity\Household;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HouseholdController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/v1/household/join', name: 'api_household_join', methods: ['POST'])]
    public function join(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (! $user instanceof User) {
            return new JsonResponse([
                'error' => 'Unauthorized.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        try {
            /** @var array<string, mixed> $data */
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse([
                'error' => 'Invalid JSON.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $inviteCode = isset($data['invite_code']) && is_string($data['invite_code']) ? trim($data['invite_code']) : '';

        if ($inviteCode === '') {
            return new JsonResponse([
                'errors' => [
                    'invite_code' => 'Invite code is required.',
                ],
            ], Response::HTTP_BAD_REQUEST);
        }

        $household = $this->entityManager->getRepository(Household::class)->findOneBy([
            'inviteCode' => $inviteCode,
        ]);

        if (! $household instanceof Household) {
            return new JsonResponse([
                'errors' => [
                    'invite_code' => 'Invalid invite code.',
                ],
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($user->getHousehold()->getId()->equals($household->getId())) {
            return new JsonResponse([
                'errors' => [
                    'invite_code' => 'You are already a member of this household.',
                ],
            ], Response::HTTP_BAD_REQUEST);
        }

        $user->setHousehold($household);
        $this->entityManager->flush();

        return new JsonResponse([
            'household' => [
                'id' => $household->getId()->toRfc4122(),
                'name' => $household->getName(),
                'invite_code' => $household->getInviteCode(),
            ],
        ]);
    }

    private function getAuthenticatedUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
