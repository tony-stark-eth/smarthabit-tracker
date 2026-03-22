<?php

declare(strict_types=1);

namespace Tests\Integration\Gdpr;

use App\Shared\Controller\GdprController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversClass(GdprController::class)]
final class GdprFlowTest extends WebTestCase
{
    public function testExportReturnsUserDataWithContentDispositionHeader(): void
    {
        $email = uniqid('export_', true) . '@example.com';
        $token = $this->registerUserAndGetToken($email);

        $client = self::createClient();
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/api/v1/user/export',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
        );

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($client->getResponse()->headers->has('Content-Disposition'));

        /** @var array{user: array{email: string, display_name: string}, household: array<string, mixed>, habits: mixed[]} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('user', $data);
        self::assertArrayHasKey('household', $data);
        self::assertArrayHasKey('habits', $data);
        self::assertSame($email, $data['user']['email']);
    }

    public function testPrivacyEndpointIsPublicAndHasVersionKey(): void
    {
        $client = self::createClient();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/api/v1/privacy');

        self::assertResponseStatusCodeSame(200);

        /** @var array{version: string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('version', $data);
        self::assertNotEmpty($data['version']);
    }

    public function testDeleteAccountRemovesUserAndSubsequentRequestFails(): void
    {
        $email = uniqid('delete_', true) . '@example.com';
        $token = $this->registerUserAndGetToken($email);

        $client = self::createClient();
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_DELETE,
            '/api/v1/user/me',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
        );

        self::assertResponseStatusCodeSame(200);

        // After deletion, using the same token should fail since the user no longer exists
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/api/v1/user/me',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
        );

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Registers a new user in a fresh kernel and returns the JWT token.
     * Uses ensureKernelShutdown() so the caller can create a new client
     * with a clean EntityManager identity map.
     */
    private function registerUserAndGetToken(string $email): string
    {
        $client = self::createClient();
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_POST,
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

        // Shut down the kernel so the next createClient() starts fresh,
        // ensuring the EntityManager identity map is cleared between requests.
        self::ensureKernelShutdown();

        return $token;
    }
}
