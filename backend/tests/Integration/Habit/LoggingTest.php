<?php

declare(strict_types=1);

namespace Tests\Integration\Habit;

use App\Habit\Controller\LogController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(LogController::class)]
final class LoggingTest extends WebTestCase
{
    public function testCreateLogReturns201(): void
    {
        $token = $this->registerAndGetToken();
        $habit = $this->createHabit($token);

        $client = self::createClient();
        $client->request(
            Request::METHOD_POST,
            '/api/v1/habits/' . $habit['id'] . '/log',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'source' => 'manual',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(201);

        /** @var array{id: string, habit_id: string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('id', $data);
        self::assertSame($habit['id'], $data['habit_id']);
    }

    public function testDeleteLogRemovesIt(): void
    {
        $token = $this->registerAndGetToken();
        $habit = $this->createHabit($token);
        $log = $this->createLog($token, $habit['id']);

        $client = self::createClient();
        $client->request(
            Request::METHOD_DELETE,
            '/api/v1/habits/' . $habit['id'] . '/log/' . $log['id'],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testHistoryReturnsPaginatedLogsOrderedByLoggedAtDesc(): void
    {
        $token = $this->registerAndGetToken();
        $habit = $this->createHabit($token);

        $this->createLog($token, $habit['id']);
        $this->createLog($token, $habit['id']);

        $client = self::createClient();
        $client->request(
            Request::METHOD_GET,
            '/api/v1/habits/' . $habit['id'] . '/history',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
        );

        self::assertResponseStatusCodeSame(200);

        /** @var array{data: list<array{id: string, logged_at: string}>, total: int, page: int, limit: int} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('data', $data);
        self::assertArrayHasKey('total', $data);
        self::assertSame(2, $data['total']);
        self::assertCount(2, $data['data']);

        // Verify DESC order: first entry loggedAt >= second entry loggedAt
        self::assertGreaterThanOrEqual(
            $data['data'][1]['logged_at'],
            $data['data'][0]['logged_at'],
        );
    }

    public function testCannotLogToAnotherHouseholdsHabit(): void
    {
        $tokenA = $this->registerAndGetToken();
        $habitA = $this->createHabit($tokenA, 'User A Habit');

        $tokenB = $this->registerAndGetToken();

        $client = self::createClient();
        $client->request(
            Request::METHOD_POST,
            '/api/v1/habits/' . $habitA['id'] . '/log',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenB,
            ],
            json_encode([
                'source' => 'manual',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
    }

    private function registerAndGetToken(?string $email = null): string
    {
        $client = self::createClient();
        $email ??= uniqid('test_', true) . '@example.com';

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

    /**
     * @return array{id: string, name: string, frequency: string}
     */
    private function createHabit(string $token, string $name = 'Test Habit'): array
    {
        $client = self::createClient();
        $client->request(
            Request::METHOD_POST,
            '/api/v1/habits',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'name' => $name,
                'frequency' => 'daily',
            ], JSON_THROW_ON_ERROR),
        );

        /** @var array{id: string, name: string, frequency: string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::ensureKernelShutdown();

        return $data;
    }

    /**
     * @return array{id: string, habit_id: string, logged_at: string, source: string}
     */
    private function createLog(string $token, string $habitId): array
    {
        $client = self::createClient();
        $client->request(
            Request::METHOD_POST,
            '/api/v1/habits/' . $habitId . '/log',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'source' => 'manual',
            ], JSON_THROW_ON_ERROR),
        );

        /** @var array{id: string, habit_id: string, logged_at: string, source: string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::ensureKernelShutdown();

        return $data;
    }
}
