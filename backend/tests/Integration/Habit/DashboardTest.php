<?php

declare(strict_types=1);

namespace Tests\Integration\Habit;

use App\Habit\Controller\DashboardController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(DashboardController::class)]
final class DashboardTest extends WebTestCase
{
    public function testDashboardReturnsHabitsWithSummary(): void
    {
        $token = $this->registerAndGetToken();
        $this->createHabit($token, 'Morning Run');

        $client = self::createClient();
        $client->request(
            Request::METHOD_GET,
            '/api/v1/dashboard',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
        );

        self::assertResponseStatusCodeSame(200);

        /** @var array{habits: list<array<string, mixed>>, summary: array{total: int, done: int, completion_rate: float}} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('habits', $data);
        self::assertArrayHasKey('summary', $data);
        self::assertCount(1, $data['habits']);
        self::assertSame(1, $data['summary']['total']);
    }

    public function testDashboardShowsIsDoneTodayAfterLogging(): void
    {
        $token = $this->registerAndGetToken();
        $habit = $this->createHabit($token, 'Daily Habit');

        $this->createLog($token, $habit['id']);

        $client = self::createClient();
        $client->request(
            Request::METHOD_GET,
            '/api/v1/dashboard',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
        );

        self::assertResponseStatusCodeSame(200);

        /** @var array{habits: list<array{id: string, is_done_today: bool}>, summary: array{done: int}} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $found = false;
        foreach ($data['habits'] as $h) {
            if ($h['id'] === $habit['id']) {
                self::assertTrue($h['is_done_today']);
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Habit was not found in dashboard response.');
        self::assertSame(1, $data['summary']['done']);
    }

    public function testDashboardExcludesSoftDeletedHabits(): void
    {
        $token = $this->registerAndGetToken();
        $habit = $this->createHabit($token, 'To Be Deleted');

        $client = self::createClient();
        $client->request(
            Request::METHOD_DELETE,
            '/api/v1/habits/' . $habit['id'],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
        );

        self::assertResponseStatusCodeSame(200);
        self::ensureKernelShutdown();

        $client = self::createClient();
        $client->request(
            Request::METHOD_GET,
            '/api/v1/dashboard',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
        );

        self::assertResponseStatusCodeSame(200);

        /** @var array{habits: list<array{id: string}>} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $ids = array_column($data['habits'], 'id');

        self::assertNotContains($habit['id'], $ids);
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
