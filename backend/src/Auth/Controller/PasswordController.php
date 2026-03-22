<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PasswordController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'limiter.password_forgot')]
        private readonly RateLimiterFactory $passwordForgotLimiter,
    ) {
    }

    #[Route('/api/v1/password/forgot', name: 'api_password_forgot', methods: ['POST'])]
    public function forgot(Request $request): JsonResponse
    {
        $limiter = $this->passwordForgotLimiter->create($request->getClientIp() ?? 'unknown');
        $limit = $limiter->consume();

        if (! $limit->isAccepted()) {
            return new JsonResponse(
                [
                    'error' => $this->translator->trans('auth.rate_limit_exceeded'),
                ],
                Response::HTTP_TOO_MANY_REQUESTS,
                [
                    'Retry-After' => $limit->getRetryAfter()->getTimestamp() - time(),
                ],
            );
        }

        return new JsonResponse([
            'message' => $this->translator->trans('auth.password.forgot_success'),
        ]);
    }

    #[Route('/api/v1/password/reset', name: 'api_password_reset', methods: ['POST'])]
    public function reset(): JsonResponse
    {
        return new JsonResponse(
            [
                'error' => $this->translator->trans('auth.password.reset_not_implemented'),
            ],
            Response::HTTP_NOT_IMPLEMENTED,
        );
    }

    #[Route('/api/v1/user/password', name: 'api_user_password_change', methods: ['PUT'])]
    public function change(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (! $user instanceof User) {
            return new JsonResponse(
                [
                    'error' => $this->translator->trans('auth.unauthorized'),
                ],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        try {
            /** @var array<string, mixed> $data */
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(
                [
                    'error' => $this->translator->trans('auth.invalid_json'),
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (! isset($data['current_password']) || ! is_string($data['current_password'])) {
            return new JsonResponse(
                [
                    'errors' => [
                        'current_password' => $this->translator->trans('auth.password.current_required'),
                    ],
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (! isset($data['new_password']) || ! is_string($data['new_password']) || mb_strlen($data['new_password']) < 8) {
            return new JsonResponse(
                [
                    'errors' => [
                        'new_password' => $this->translator->trans('auth.password.new_too_short'),
                    ],
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (! $this->passwordHasher->isPasswordValid($user, $data['current_password'])) {
            return new JsonResponse(
                [
                    'errors' => [
                        'current_password' => $this->translator->trans('auth.password.current_invalid'),
                    ],
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $hashedPassword = $this->passwordHasher->hashPassword($user, $data['new_password']);
        $user->setPassword($hashedPassword);
        $this->entityManager->flush();

        return new JsonResponse([
            'message' => $this->translator->trans('auth.password.change_success'),
        ]);
    }
}
