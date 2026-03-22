<?php

declare(strict_types=1);

namespace App\Notification\Controller;

use App\Auth\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PushSubscriptionController extends AbstractController
{
    private const array VALID_TYPES = ['web_push', 'ntfy', 'apns'];

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $vapidPublicKey,
        private readonly string $ntfyServerUrl = '',
    ) {
    }

    #[Route('/api/v1/vapid-key', name: 'api_vapid_key', methods: ['GET'])]
    public function vapidKey(): JsonResponse
    {
        $response = [
            'publicKey' => $this->vapidPublicKey,
        ];

        if ($this->ntfyServerUrl !== '') {
            $response['ntfy_server_url'] = $this->ntfyServerUrl;
        }

        return new JsonResponse($response);
    }

    #[Route('/api/v1/user/push-subscription', name: 'api_push_subscription_add', methods: ['POST'])]
    public function add(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (! $user instanceof User) {
            return $this->unauthorizedResponse();
        }

        $data = $this->parseJson($request);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        $typeError = $this->validateType($data);

        if ($typeError instanceof JsonResponse) {
            return $typeError;
        }

        $type = isset($data['type']) && is_string($data['type']) ? $data['type'] : '';

        $validationError = $this->validatePayloadForType($type, $data);

        if ($validationError instanceof JsonResponse) {
            return $validationError;
        }

        $deviceName = isset($data['device_name']) && is_string($data['device_name'])
            ? mb_substr($data['device_name'], 0, 50)
            : '';

        $this->upsertSubscription($user, $type, $data, $deviceName);
        $this->entityManager->flush();

        return new JsonResponse([
            'status' => 'ok',
        ], Response::HTTP_OK);
    }

    #[Route('/api/v1/user/push-subscription', name: 'api_push_subscription_remove', methods: ['DELETE'])]
    public function remove(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (! $user instanceof User) {
            return $this->unauthorizedResponse();
        }

        $data = $this->parseJson($request);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        $typeError = $this->validateType($data);

        if ($typeError instanceof JsonResponse) {
            return $typeError;
        }

        $type = isset($data['type']) && is_string($data['type']) ? $data['type'] : '';

        $identifierError = $this->validateDeleteIdentifier($type, $data);

        if ($identifierError instanceof JsonResponse) {
            return $identifierError;
        }

        $identifier = $this->getIdentifierForType($type, $data);

        /** @var array<int, array<string, mixed>> $subscriptions */
        $subscriptions = $user->getPushSubscriptions() ?? [];

        $identifierKey = match ($type) {
            'web_push' => 'endpoint',
            'ntfy' => 'topic',
            default => 'device_token',
        };

        $filtered = array_values(
            array_filter(
                $subscriptions,
                static fn (array $sub): bool => ($sub[$identifierKey] ?? null) !== $identifier,
            ),
        );

        $user->setPushSubscriptions($filtered !== [] ? $filtered : null);
        $this->entityManager->flush();

        return new JsonResponse([
            'status' => 'ok',
        ], Response::HTTP_OK);
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

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function parseJson(Request $request): array|JsonResponse
    {
        try {
            /** @var array<string, mixed> $data */
            $data = $request->toArray();

            return $data;
        } catch (\Throwable) {
            return new JsonResponse([
                'error' => 'Invalid JSON.',
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateType(array $data): ?JsonResponse
    {
        if (! isset($data['type']) || ! is_string($data['type']) || $data['type'] === '') {
            return new JsonResponse([
                'error' => 'Missing or invalid type.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (! in_array($data['type'], self::VALID_TYPES, true)) {
            return new JsonResponse(
                [
                    'error' => 'Invalid type. Must be one of: ' . implode(', ', self::VALID_TYPES) . '.',
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validatePayloadForType(string $type, array $data): ?JsonResponse
    {
        return match ($type) {
            'web_push' => $this->validateWebPushPayload($data),
            'ntfy' => $this->validateNtfyPayload($data),
            'apns' => $this->validateApnsPayload($data),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateWebPushPayload(array $data): ?JsonResponse
    {
        if (! isset($data['endpoint']) || ! is_string($data['endpoint']) || $data['endpoint'] === '') {
            return new JsonResponse([
                'error' => 'Missing or invalid endpoint.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (! isset($data['keys']) || ! is_array($data['keys'])) {
            return new JsonResponse([
                'error' => 'Missing keys.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $keys = $data['keys'];

        if (! isset($keys['p256dh']) || ! is_string($keys['p256dh']) || $keys['p256dh'] === '') {
            return new JsonResponse([
                'error' => 'Missing or invalid keys.p256dh.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (! isset($keys['auth']) || ! is_string($keys['auth']) || $keys['auth'] === '') {
            return new JsonResponse([
                'error' => 'Missing or invalid keys.auth.',
            ], Response::HTTP_BAD_REQUEST);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateNtfyPayload(array $data): ?JsonResponse
    {
        if (! isset($data['topic']) || ! is_string($data['topic']) || $data['topic'] === '') {
            return new JsonResponse([
                'error' => 'Missing or invalid topic.',
            ], Response::HTTP_BAD_REQUEST);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateApnsPayload(array $data): ?JsonResponse
    {
        if (! isset($data['device_token']) || ! is_string($data['device_token'])) {
            return new JsonResponse([
                'error' => 'Missing or invalid device_token.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $token = $data['device_token'];

        if (mb_strlen($token) < 64) {
            return new JsonResponse([
                'error' => 'device_token must be at least 64 characters.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (preg_match('/^[0-9a-fA-F]+$/', $token) !== 1) {
            return new JsonResponse([
                'error' => 'device_token must contain only hex characters.',
            ], Response::HTTP_BAD_REQUEST);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateDeleteIdentifier(string $type, array $data): ?JsonResponse
    {
        return match ($type) {
            'web_push' => (! isset($data['endpoint']) || ! is_string($data['endpoint']) || $data['endpoint'] === '')
                ? new JsonResponse([
                    'error' => 'Missing endpoint.',
                ], Response::HTTP_BAD_REQUEST)
                : null,
            'ntfy' => (! isset($data['topic']) || ! is_string($data['topic']) || $data['topic'] === '')
                ? new JsonResponse([
                    'error' => 'Missing topic.',
                ], Response::HTTP_BAD_REQUEST)
                : null,
            'apns' => (! isset($data['device_token']) || ! is_string($data['device_token']) || $data['device_token'] === '')
                ? new JsonResponse([
                    'error' => 'Missing device_token.',
                ], Response::HTTP_BAD_REQUEST)
                : null,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function getIdentifierForType(string $type, array $data): string
    {
        $key = match ($type) {
            'web_push' => 'endpoint',
            'ntfy' => 'topic',
            default => 'device_token',
        };

        $value = $data[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function upsertSubscription(User $user, string $type, array $data, string $deviceName): void
    {
        /** @var array<int, array<string, mixed>> $subscriptions */
        $subscriptions = $user->getPushSubscriptions() ?? [];

        $now = new \DateTimeImmutable()->format(\DateTimeInterface::ATOM);
        $identifier = $this->getIdentifierForType($type, $data);
        $identifierKey = match ($type) {
            'web_push' => 'endpoint',
            'ntfy' => 'topic',
            default => 'device_token',
        };

        $found = false;

        foreach ($subscriptions as $index => $sub) {
            if (($sub[$identifierKey] ?? null) === $identifier && ($sub['type'] ?? null) === $type) {
                $subscriptions[$index] = $this->buildSubscriptionEntry($type, $data, $deviceName, $now);
                $found = true;
                break;
            }
        }

        if (! $found) {
            $subscriptions[] = $this->buildSubscriptionEntry($type, $data, $deviceName, $now);
        }

        $user->setPushSubscriptions($subscriptions);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function buildSubscriptionEntry(string $type, array $data, string $deviceName, string $now): array
    {
        $entry = [
            'type' => $type,
            'device_name' => $deviceName,
            'last_seen' => $now,
        ];

        return match ($type) {
            'web_push' => array_merge($entry, [
                'endpoint' => $this->getIdentifierForType('web_push', $data),
                'keys' => is_array($data['keys'] ?? null) ? $data['keys'] : [],
            ]),
            'ntfy' => array_merge($entry, [
                'topic' => $this->getIdentifierForType('ntfy', $data),
            ]),
            'apns' => array_merge($entry, [
                'device_token' => $this->getIdentifierForType('apns', $data),
            ]),
            default => $entry,
        };
    }
}
