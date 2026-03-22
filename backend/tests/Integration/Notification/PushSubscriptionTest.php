<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use App\Auth\Entity\User;
use App\Notification\Controller\PushSubscriptionController;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(PushSubscriptionController::class)]
final class PushSubscriptionTest extends WebTestCase
{
    public function testPostPushSubscriptionCreatesEntry(): void
    {
        $email = uniqid('push_', true) . '@example.com';
        $token = $this->registerAndGetToken($email);
        $client = self::createClient();

        $client->request(
            Request::METHOD_POST,
            '/api/v1/user/push-subscription',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'endpoint' => 'https://push.example.com/sub/unique-1',
                'keys' => [
                    'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlTiKWhk1jIwhte_0t6f5gJW-eOb3xYYLK',
                    'auth' => 'tBHItJI5svbpez7KI4CCXg',
                ],
                'device_name' => 'Test Browser',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);

        /** @var array{status: string} $responseData */
        $responseData = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $responseData['status']);

        // Verify via EntityManager
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $user = $em->getRepository(User::class)->findOneBy([
            'email' => $email,
        ]);
        assert($user instanceof User);

        $subscriptions = $user->getPushSubscriptions();
        self::assertNotNull($subscriptions);
        self::assertCount(1, $subscriptions);
        self::assertArrayHasKey(0, $subscriptions);

        /** @var array{endpoint: string} $first */
        $first = $subscriptions[0];
        self::assertSame('https://push.example.com/sub/unique-1', $first['endpoint']);
    }

    public function testPostSameEndpointTwiceUpserts(): void
    {
        $email = uniqid('push_upsert_', true) . '@example.com';
        $token = $this->registerAndGetToken($email);
        $client = self::createClient();

        $payload = json_encode([
            'endpoint' => 'https://push.example.com/sub/upsert-test',
            'keys' => [
                'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlTiKWhk1jIwhte_0t6f5gJW-eOb3xYYLK',
                'auth' => 'tBHItJI5svbpez7KI4CCXg',
            ],
            'device_name' => 'My Device',
        ], JSON_THROW_ON_ERROR);

        $client->request(
            Request::METHOD_POST,
            '/api/v1/user/push-subscription',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            $payload,
        );
        self::assertResponseStatusCodeSame(200);

        $client->request(
            Request::METHOD_POST,
            '/api/v1/user/push-subscription',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            $payload,
        );
        self::assertResponseStatusCodeSame(200);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->clear();

        $user = $em->getRepository(User::class)->findOneBy([
            'email' => $email,
        ]);
        assert($user instanceof User);

        $subscriptions = $user->getPushSubscriptions();
        self::assertNotNull($subscriptions);
        self::assertCount(1, $subscriptions);
    }

    public function testDeletePushSubscriptionRemovesEntry(): void
    {
        $email = uniqid('push_del_', true) . '@example.com';
        $token = $this->registerAndGetToken($email);
        $client = self::createClient();

        $endpoint = 'https://push.example.com/sub/delete-test';

        $client->request(
            Request::METHOD_POST,
            '/api/v1/user/push-subscription',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'endpoint' => $endpoint,
                'keys' => [
                    'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlTiKWhk1jIwhte_0t6f5gJW-eOb3xYYLK',
                    'auth' => 'tBHItJI5svbpez7KI4CCXg',
                ],
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(200);

        $client->request(
            Request::METHOD_DELETE,
            '/api/v1/user/push-subscription',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'endpoint' => $endpoint,
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(200);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->clear();

        $user = $em->getRepository(User::class)->findOneBy([
            'email' => $email,
        ]);
        assert($user instanceof User);

        $subscriptions = $user->getPushSubscriptions();
        self::assertNull($subscriptions);
    }

    public function testGetVapidKeyReturnsPublicKeyWithoutAuth(): void
    {
        $client = self::createClient();

        $client->request(Request::METHOD_GET, '/api/v1/vapid-key');

        self::assertResponseStatusCodeSame(200);

        /** @var array{publicKey: string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('publicKey', $data);
        self::assertNotEmpty($data['publicKey']);
    }

    public function testUnauthenticatedPostReturns401(): void
    {
        $client = self::createClient();

        $client->request(
            Request::METHOD_POST,
            '/api/v1/user/push-subscription',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'endpoint' => 'https://push.example.com/sub/no-auth',
                'keys' => [
                    'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlTiKWhk1jIwhte_0t6f5gJW-eOb3xYYLK',
                    'auth' => 'tBHItJI5svbpez7KI4CCXg',
                ],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
    }

    private function registerAndGetToken(string $email): string
    {
        $client = self::createClient();

        $client->request(
            Request::METHOD_POST,
            '/api/v1/register',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'email' => $email,
                'password' => 'password123',
                'display_name' => 'Test User',
                'timezone' => 'Europe/Berlin',
                'locale' => 'en',
                'household_name' => 'Test Household',
                'consent' => true,
            ], JSON_THROW_ON_ERROR),
        );

        /** @var array{token: string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $token = $data['token'];

        self::ensureKernelShutdown();

        return $token;
    }
}
