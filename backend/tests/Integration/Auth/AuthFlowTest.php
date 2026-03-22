<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Auth\Controller\RegisterController;
use App\Auth\Controller\UserController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversClass(RegisterController::class)]
#[CoversClass(UserController::class)]
final class AuthFlowTest extends WebTestCase
{
    public function testRegisterWithNewHouseholdReturns201WithToken(): void
    {
        $client = self::createClient();
        $email = uniqid('register_', true) . '@example.com';

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

        self::assertResponseStatusCodeSame(201);

        /** @var array{token: string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('token', $data);
        self::assertNotEmpty($data['token']);
    }

    public function testRegisterWithMissingConsentReturns400(): void
    {
        $client = self::createClient();
        $email = uniqid('no_consent_', true) . '@example.com';

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
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testRegisterWithDuplicateEmailReturnsError(): void
    {
        $client = self::createClient();
        $email = uniqid('duplicate_', true) . '@example.com';

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

        self::assertResponseStatusCodeSame(201);

        // Attempt to register again with the same email
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
                'display_name' => 'Another User',
                'timezone' => 'Europe/Berlin',
                'locale' => 'en',
                'household_name' => 'Another Household',
                'consent' => true,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);

        /** @var array{errors: array<string, string>} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('errors', $data);
    }

    public function testLoginWithCorrectCredentialsReturns200WithToken(): void
    {
        $email = uniqid('login_', true) . '@example.com';
        $password = 'password123';

        $this->registerUserAndShutdown($email, $password);

        $client = self::createClient();
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_POST,
            '/api/v1/login',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'email' => $email,
                'password' => $password,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);

        /** @var array{token: string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('token', $data);
        self::assertNotEmpty($data['token']);
    }

    public function testLoginWithWrongPasswordReturns401(): void
    {
        $email = uniqid('wrong_pw_', true) . '@example.com';

        $this->registerUserAndShutdown($email);

        $client = self::createClient();
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_POST,
            '/api/v1/login',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'email' => $email,
                'password' => 'wrongpassword',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testGetMeWithValidJwtReturnsUserData(): void
    {
        $email = uniqid('me_', true) . '@example.com';
        $token = $this->registerUserAndGetToken($email);

        $client = self::createClient();
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

        self::assertResponseStatusCodeSame(200);

        /** @var array{user: array{email: string, display_name: string}} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('user', $data);
        self::assertSame($email, $data['user']['email']);
        self::assertArrayHasKey('display_name', $data['user']);
    }

    public function testPutMeUpdatesProfile(): void
    {
        $email = uniqid('update_', true) . '@example.com';
        $token = $this->registerUserAndGetToken($email);

        $client = self::createClient();
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_PUT,
            '/api/v1/user/me',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'display_name' => 'Updated Name',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);

        /** @var array{user: array{display_name: string}} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Updated Name', $data['user']['display_name']);
    }

    public function testJoinExistingHouseholdViaInviteCode(): void
    {
        $emailA = uniqid('household_a_', true) . '@example.com';
        $emailB = uniqid('household_b_', true) . '@example.com';

        // Register user A and get their invite code via the export endpoint
        $tokenA = $this->registerUserAndGetToken($emailA);

        $clientA = self::createClient();
        $clientA->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/api/v1/user/export',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenA,
            ],
        );

        /** @var array{household: array{invite_code: string}} $exportData */
        $exportData = json_decode((string) $clientA->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $inviteCode = $exportData['household']['invite_code'];

        self::ensureKernelShutdown();

        // Register user B with invite code instead of household_name
        $tokenB = $this->registerUserAndGetToken($emailB, 'password123', $inviteCode);

        // Verify user B is in the same household as user A
        $clientB = self::createClient();
        $clientB->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/api/v1/user/export',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenB,
            ],
        );

        /** @var array{household: array{invite_code: string}} $exportDataB */
        $exportDataB = json_decode((string) $clientB->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($inviteCode, $exportDataB['household']['invite_code']);
    }

    /**
     * Registers a user in a fresh kernel, returns the JWT token, and shuts down
     * the kernel so the caller can create a new client with a clean EntityManager.
     */
    private function registerUserAndGetToken(string $email, string $password = 'password123', ?string $inviteCode = null): string
    {
        $client = self::createClient();

        $payload = [
            'email' => $email,
            'password' => $password,
            'display_name' => 'Test User',
            'timezone' => 'Europe/Berlin',
            'locale' => 'en',
            'consent' => true,
        ];

        if ($inviteCode !== null) {
            $payload['invite_code'] = $inviteCode;
        } else {
            $payload['household_name'] = 'Test Household';
        }

        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_POST,
            '/api/v1/register',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );

        /** @var array{token: string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $token = $data['token'];

        self::ensureKernelShutdown();

        return $token;
    }

    /**
     * Registers a user and shuts down the kernel (token not needed by caller).
     */
    private function registerUserAndShutdown(string $email, string $password = 'password123'): void
    {
        $this->registerUserAndGetToken($email, $password);
    }
}
