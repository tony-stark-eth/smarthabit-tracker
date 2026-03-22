<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\Entity\User;
use App\Household\Entity\Household;
use App\Shared\Enum\Locale;
use App\Shared\Enum\Theme;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RegisterController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'limiter.register')]
        private readonly RateLimiterFactory $registerLimiter,
    ) {
    }

    #[Route('/api/v1/register', name: 'api_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $rateLimitResponse = $this->checkRateLimit($request);

        if ($rateLimitResponse instanceof JsonResponse) {
            return $rateLimitResponse;
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

        $errors = $this->validateRequired($data);
        $errors = [...$errors, ...$this->validateOptional($data)];

        if ($errors !== []) {
            return new JsonResponse(
                [
                    'errors' => $errors,
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        return $this->createUser($data);
    }

    private function checkRateLimit(Request $request): ?JsonResponse
    {
        $limiter = $this->registerLimiter->create($request->getClientIp() ?? 'unknown');
        $limit = $limiter->consume();

        if ($limit->isAccepted()) {
            return null;
        }

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

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private function validateRequired(array $data): array
    {
        $errors = [];

        if (! isset($data['email']) || ! is_string($data['email']) || filter_var($data['email'], \FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = $this->translator->trans('auth.register.invalid_email');
        }

        if (! isset($data['password']) || ! is_string($data['password']) || mb_strlen($data['password']) < 8) {
            $errors['password'] = $this->translator->trans('auth.register.password_too_short');
        }

        if (! isset($data['display_name']) || ! is_string($data['display_name']) || trim($data['display_name']) === '') {
            $errors['display_name'] = $this->translator->trans('auth.register.display_name_required');
        }

        if (! isset($data['consent']) || $data['consent'] === false) {
            $errors['consent'] = $this->translator->trans('auth.register.consent_required');
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private function validateOptional(array $data): array
    {
        $errors = [];

        if (isset($data['timezone']) && is_string($data['timezone']) && ! in_array($data['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = $this->translator->trans('auth.register.invalid_timezone');
        }

        if (! $this->hasHouseholdIdentifier($data)) {
            $errors['household'] = $this->translator->trans('auth.register.household_required');
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hasHouseholdIdentifier(array $data): bool
    {
        $hasHouseholdName = isset($data['household_name']) && is_string($data['household_name']) && trim($data['household_name']) !== '';
        $hasInviteCode = isset($data['invite_code']) && is_string($data['invite_code']) && trim($data['invite_code']) !== '';

        return $hasHouseholdName || $hasInviteCode;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createUser(array $data): JsonResponse
    {
        $email = is_string($data['email'] ?? null) ? $data['email'] : '';
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        $displayName = is_string($data['display_name'] ?? null) ? $data['display_name'] : '';
        $timezone = is_string($data['timezone'] ?? null) ? $data['timezone'] : 'Europe/Berlin';
        $localeValue = is_string($data['locale'] ?? null) ? $data['locale'] : 'de';
        $locale = Locale::tryFrom($localeValue) ?? Locale::DE;
        $consentValue = is_string($data['consent'] ?? null) ? $data['consent'] : '1.0';

        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy([
            'email' => $email,
        ]);

        if ($existingUser instanceof User) {
            return new JsonResponse(
                [
                    'errors' => [
                        'email' => $this->translator->trans('auth.register.email_taken'),
                    ],
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $household = $this->resolveHousehold($data);

        if (! $household instanceof Household) {
            return new JsonResponse(
                [
                    'errors' => [
                        'invite_code' => $this->translator->trans('auth.register.invalid_invite_code'),
                    ],
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $user = new User(
            household: $household,
            email: $email,
            password: '',
            displayName: $displayName,
            timezone: $timezone,
            locale: $locale,
            theme: Theme::AUTO,
        );

        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);
        $user->giveConsent($consentValue);

        $this->entityManager->persist($household);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $token = $this->jwtManager->create($user);

        return new JsonResponse([
            'token' => $token,
            'user' => [
                'id' => $user->getId()->toRfc4122(),
                'email' => $user->getEmail(),
                'display_name' => $user->getDisplayName(),
                'timezone' => $user->getTimezone(),
                'locale' => $user->getLocale()->value,
                'theme' => $user->getTheme()->value,
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveHousehold(array $data): ?Household
    {
        if (isset($data['invite_code']) && is_string($data['invite_code']) && trim($data['invite_code']) !== '') {
            return $this->entityManager->getRepository(Household::class)->findOneBy([
                'inviteCode' => trim($data['invite_code']),
            ]);
        }

        $householdName = is_string($data['household_name'] ?? null) ? $data['household_name'] : '';

        return new Household($householdName);
    }
}
