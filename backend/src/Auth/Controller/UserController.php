<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\Entity\User;
use App\Shared\Enum\Locale;
use App\Shared\Enum\Theme;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class UserController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/api/v1/user/me', name: 'api_user_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (! $user instanceof User) {
            return $this->unauthorizedResponse();
        }

        return new JsonResponse([
            'user' => $this->serializeUser($user),
        ]);
    }

    #[Route('/api/v1/user/me', name: 'api_user_me_update', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (! $user instanceof User) {
            return $this->unauthorizedResponse();
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

        $errorResponse = $this->applyUpdates($user, $data);

        if ($errorResponse instanceof JsonResponse) {
            return $errorResponse;
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'user' => $this->serializeUser($user),
        ]);
    }

    private function getAuthenticatedUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
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

    /**
     * @param array<string, mixed> $data
     */
    private function applyUpdates(User $user, array $data): ?JsonResponse
    {
        if (isset($data['display_name']) && is_string($data['display_name']) && trim($data['display_name']) !== '') {
            $user->setDisplayName(trim($data['display_name']));
        }

        $timezoneError = $this->applyTimezone($user, $data);

        if ($timezoneError instanceof JsonResponse) {
            return $timezoneError;
        }

        $localeError = $this->applyLocale($user, $data);

        if ($localeError instanceof JsonResponse) {
            return $localeError;
        }

        return $this->applyTheme($user, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyTimezone(User $user, array $data): ?JsonResponse
    {
        if (! isset($data['timezone']) || ! is_string($data['timezone'])) {
            return null;
        }

        if (! in_array($data['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            return new JsonResponse(
                [
                    'errors' => [
                        'timezone' => $this->translator->trans('auth.register.invalid_timezone'),
                    ],
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $user->setTimezone($data['timezone']);

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyLocale(User $user, array $data): ?JsonResponse
    {
        if (! isset($data['locale']) || ! is_string($data['locale'])) {
            return null;
        }

        $locale = Locale::tryFrom($data['locale']);

        if (! $locale instanceof Locale) {
            return new JsonResponse(
                [
                    'errors' => [
                        'locale' => $this->translator->trans('auth.user.invalid_locale'),
                    ],
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $user->setLocale($locale);

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyTheme(User $user, array $data): ?JsonResponse
    {
        if (! isset($data['theme']) || ! is_string($data['theme'])) {
            return null;
        }

        $theme = Theme::tryFrom($data['theme']);

        if (! $theme instanceof Theme) {
            return new JsonResponse(
                [
                    'errors' => [
                        'theme' => $this->translator->trans('auth.user.invalid_theme'),
                    ],
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $user->setTheme($theme);

        return null;
    }

    /**
     * @return array{id: string, email: string, display_name: string, timezone: string, locale: string, theme: string, household: array{id: string, name: string, invite_code: string}}
     */
    private function serializeUser(User $user): array
    {
        $household = $user->getHousehold();

        return [
            'id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
            'display_name' => $user->getDisplayName(),
            'timezone' => $user->getTimezone(),
            'locale' => $user->getLocale()->value,
            'theme' => $user->getTheme()->value,
            'household' => [
                'id' => $household->getId()->toRfc4122(),
                'name' => $household->getName(),
                'invite_code' => $household->getInviteCode(),
            ],
        ];
    }
}
