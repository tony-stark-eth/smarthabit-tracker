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
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $vapidPublicKey,
    ) {
    }

    #[Route('/api/v1/vapid-key', name: 'api_vapid_key', methods: ['GET'])]
    public function vapidKey(): JsonResponse
    {
        return new JsonResponse([
            'publicKey' => $this->vapidPublicKey,
        ]);
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

        $validationError = $this->validateSubscriptionPayload($data);

        if ($validationError instanceof JsonResponse) {
            return $validationError;
        }

        /** @var array{endpoint: string, keys: array{p256dh: string, auth: string}, device_name?: string} $data */
        $endpoint = $data['endpoint'];
        $keys = $data['keys'];
        $deviceName = isset($data['device_name']) && is_string($data['device_name']) ? $data['device_name'] : '';

        $this->upsertSubscription($user, $endpoint, $keys, $deviceName);
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

        if (! isset($data['endpoint']) || ! is_string($data['endpoint'])) {
            return new JsonResponse([
                'error' => 'Missing endpoint.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $endpoint = $data['endpoint'];

        /** @var array<int, array{endpoint: string, keys: array<string, string>, device_name: string, last_seen: string, type: string}> $subscriptions */
        $subscriptions = $user->getPushSubscriptions() ?? [];

        $filtered = array_values(
            array_filter($subscriptions, static fn (array $sub): bool => $sub['endpoint'] !== $endpoint),
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
    private function validateSubscriptionPayload(array $data): ?JsonResponse
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
     * @param array{p256dh: string, auth: string} $keys
     */
    private function upsertSubscription(User $user, string $endpoint, array $keys, string $deviceName): void
    {
        /** @var array<int, array{endpoint: string, keys: array<string, string>, device_name: string, last_seen: string, type: string}> $subscriptions */
        $subscriptions = $user->getPushSubscriptions() ?? [];

        $found = false;
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        foreach ($subscriptions as $index => $sub) {
            if ($sub['endpoint'] === $endpoint) {
                $subscriptions[$index]['keys'] = $keys;
                $subscriptions[$index]['device_name'] = $deviceName;
                $subscriptions[$index]['last_seen'] = $now;
                $found = true;
                break;
            }
        }

        if (! $found) {
            $subscriptions[] = [
                'type' => 'web_push',
                'endpoint' => $endpoint,
                'keys' => $keys,
                'device_name' => $deviceName,
                'last_seen' => $now,
            ];
        }

        $user->setPushSubscriptions($subscriptions);
    }
}
