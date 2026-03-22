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
    // --- web_push tests (existing, updated to include type) ---

    public function testPostWebPushSubscriptionCreatesEntry(): void
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
                'type' => 'web_push',
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

        /** @var array{type: string, endpoint: string} $first */
        $first = $subscriptions[0];
        self::assertSame('web_push', $first['type']);
        self::assertSame('https://push.example.com/sub/unique-1', $first['endpoint']);
    }

    public function testPostSameEndpointTwiceUpserts(): void
    {
        $email = uniqid('push_upsert_', true) . '@example.com';
        $token = $this->registerAndGetToken($email);
        $client = self::createClient();

        $payload = json_encode([
            'type' => 'web_push',
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

    public function testDeleteWebPushSubscriptionRemovesEntry(): void
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
                'type' => 'web_push',
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
                'type' => 'web_push',
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

    // --- ntfy tests ---

    public function testPostNtfySubscriptionCreatesEntry(): void
    {
        $email = uniqid('ntfy_create_', true) . '@example.com';
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
                'type' => 'ntfy',
                'topic' => 'my-habits-topic',
                'device_name' => 'My Phone',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);

        /** @var array{status: string} $responseData */
        $responseData = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $responseData['status']);

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
        /** @var array{type: string, topic: string, device_name: string, last_seen: mixed} $first */
        $first = $subscriptions[0];
        self::assertSame('ntfy', $first['type']);
        self::assertSame('my-habits-topic', $first['topic']);
        self::assertSame('My Phone', $first['device_name']);
        self::assertArrayHasKey('last_seen', $first);
    }

    public function testPostSameNtfyTopicTwiceUpserts(): void
    {
        $email = uniqid('ntfy_upsert_', true) . '@example.com';
        $token = $this->registerAndGetToken($email);
        $client = self::createClient();

        $payload = json_encode([
            'type' => 'ntfy',
            'topic' => 'upsert-topic',
        ], JSON_THROW_ON_ERROR);

        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ];

        $client->request(Request::METHOD_POST, '/api/v1/user/push-subscription', [], [], $headers, $payload);
        self::assertResponseStatusCodeSame(200);

        $client->request(Request::METHOD_POST, '/api/v1/user/push-subscription', [], [], $headers, $payload);
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

    public function testDeleteNtfySubscriptionRemovesEntry(): void
    {
        $email = uniqid('ntfy_del_', true) . '@example.com';
        $token = $this->registerAndGetToken($email);
        $client = self::createClient();

        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ];

        $client->request(
            Request::METHOD_POST,
            '/api/v1/user/push-subscription',
            [],
            [],
            $headers,
            json_encode([
                'type' => 'ntfy',
                'topic' => 'delete-me-topic',
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(200);

        $client->request(
            Request::METHOD_DELETE,
            '/api/v1/user/push-subscription',
            [],
            [],
            $headers,
            json_encode([
                'type' => 'ntfy',
                'topic' => 'delete-me-topic',
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

        self::assertNull($user->getPushSubscriptions());
    }

    // --- APNs tests ---

    public function testPostApnsSubscriptionCreatesEntry(): void
    {
        $email = uniqid('apns_create_', true) . '@example.com';
        $token = $this->registerAndGetToken($email);
        $client = self::createClient();

        $deviceToken = str_repeat('a1b2c3d4', 8); // 64 hex chars

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
                'type' => 'apns',
                'device_token' => $deviceToken,
                'device_name' => 'iPhone 15',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);

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
        /** @var array{type: string, device_token: string, device_name: string} $first */
        $first = $subscriptions[0];
        self::assertSame('apns', $first['type']);
        self::assertSame($deviceToken, $first['device_token']);
        self::assertSame('iPhone 15', $first['device_name']);
    }

    public function testPostSameApnsDeviceTokenTwiceUpserts(): void
    {
        $email = uniqid('apns_upsert_', true) . '@example.com';
        $token = $this->registerAndGetToken($email);
        $client = self::createClient();

        $deviceToken = str_repeat('deadbeef', 8); // 64 hex chars

        $payload = json_encode([
            'type' => 'apns',
            'device_token' => $deviceToken,
        ], JSON_THROW_ON_ERROR);

        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ];

        $client->request(Request::METHOD_POST, '/api/v1/user/push-subscription', [], [], $headers, $payload);
        self::assertResponseStatusCodeSame(200);

        $client->request(Request::METHOD_POST, '/api/v1/user/push-subscription', [], [], $headers, $payload);
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

    public function testDeleteApnsSubscriptionRemovesEntry(): void
    {
        $email = uniqid('apns_del_', true) . '@example.com';
        $token = $this->registerAndGetToken($email);
        $client = self::createClient();

        $deviceToken = str_repeat('cafebabe', 8); // 64 hex chars

        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ];

        $client->request(
            Request::METHOD_POST,
            '/api/v1/user/push-subscription',
            [],
            [],
            $headers,
            json_encode([
                'type' => 'apns',
                'device_token' => $deviceToken,
            ], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(200);

        $client->request(
            Request::METHOD_DELETE,
            '/api/v1/user/push-subscription',
            [],
            [],
            $headers,
            json_encode([
                'type' => 'apns',
                'device_token' => $deviceToken,
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

        self::assertNull($user->getPushSubscriptions());
    }

    // --- Validation error tests ---

    public function testPostWithInvalidTypeReturns400(): void
    {
        $email = uniqid('bad_type_', true) . '@example.com';
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
                'type' => 'smoke_signal',
                'endpoint' => 'https://example.com',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);

        /** @var array{error: string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Invalid type', $data['error']);
    }

    public function testPostWithMissingTypeReturns400(): void
    {
        $email = uniqid('no_type_', true) . '@example.com';
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
                'endpoint' => 'https://example.com',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testPostWebPushMissingEndpointReturns400(): void
    {
        $email = uniqid('wp_no_ep_', true) . '@example.com';
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
                'type' => 'web_push',
                'keys' => [
                    'p256dh' => 'abc',
                    'auth' => 'def',
                ],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);

        /** @var array{error: string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('endpoint', $data['error']);
    }

    public function testPostWebPushMissingKeysReturns400(): void
    {
        $email = uniqid('wp_no_keys_', true) . '@example.com';
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
                'type' => 'web_push',
                'endpoint' => 'https://push.example.com/sub/no-keys',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);

        /** @var array{error: string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('keys', $data['error']);
    }

    public function testPostNtfyMissingTopicReturns400(): void
    {
        $email = uniqid('ntfy_no_topic_', true) . '@example.com';
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
                'type' => 'ntfy',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);

        /** @var array{error: string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('topic', $data['error']);
    }

    public function testPostApnsMissingDeviceTokenReturns400(): void
    {
        $email = uniqid('apns_no_dt_', true) . '@example.com';
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
                'type' => 'apns',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);

        /** @var array{error: string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('device_token', $data['error']);
    }

    public function testPostApnsTooShortDeviceTokenReturns400(): void
    {
        $email = uniqid('apns_short_dt_', true) . '@example.com';
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
                'type' => 'apns',
                'device_token' => 'abc123',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);

        /** @var array{error: string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('64', $data['error']);
    }

    public function testPostApnsNonHexDeviceTokenReturns400(): void
    {
        $email = uniqid('apns_hex_dt_', true) . '@example.com';
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
                'type' => 'apns',
                'device_token' => str_repeat('z!@#', 16),
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);

        /** @var array{error: string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('hex', $data['error']);
    }

    // --- Existing general tests ---

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
                'type' => 'web_push',
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
