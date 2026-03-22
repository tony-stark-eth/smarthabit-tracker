<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Auth\Controller\PasswordController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversClass(PasswordController::class)]
final class PasswordFlowTest extends WebTestCase
{
    public function testForgotPasswordAlwaysReturnsSuccess(): void
    {
        $client = self::createClient();

        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_POST,
            '/api/v1/password/forgot',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'email' => 'nonexistent@example.com',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);

        /** @var array{message: string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('message', $data);
    }

    public function testChangePasswordSucceedsAndNewPasswordWorks(): void
    {
        $email = uniqid('pw_change_', true) . '@example.com';
        $oldPassword = 'password123';
        $newPassword = 'newPassword456';

        $token = $this->registerUserAndGetToken($email, $oldPassword);

        // Change the password
        $client = self::createClient();
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_PUT,
            '/api/v1/user/password',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'current_password' => $oldPassword,
                'new_password' => $newPassword,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
        self::ensureKernelShutdown();

        // Login with the new password should succeed
        $loginClient = self::createClient();
        $loginClient->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_POST,
            '/api/v1/login',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'email' => $email,
                'password' => $newPassword,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);

        /** @var array{token: string} $loginData */
        $loginData = json_decode((string) $loginClient->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('token', $loginData);
    }

    public function testChangePasswordWithWrongCurrentPasswordReturns400(): void
    {
        $email = uniqid('pw_wrong_', true) . '@example.com';
        $token = $this->registerUserAndGetToken($email);

        $client = self::createClient();
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_PUT,
            '/api/v1/user/password',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'current_password' => 'wrongcurrentpassword',
                'new_password' => 'newPassword456',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);

        /** @var array{errors: array<string, string>} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('errors', $data);
        self::assertArrayHasKey('current_password', $data['errors']);
    }

    /**
     * Registers a user in a fresh kernel, returns the JWT token, and shuts down
     * the kernel so the caller can create a new client with a clean EntityManager.
     */
    private function registerUserAndGetToken(string $email, string $password = 'password123'): string
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
                'password' => $password,
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
