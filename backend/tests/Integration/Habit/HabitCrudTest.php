<?php

declare(strict_types=1);

namespace Tests\Integration\Habit;

use App\Habit\Controller\HabitController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(HabitController::class)]
final class HabitCrudTest extends WebTestCase
{
    public function testCreateHabitReturns201WithIdAndName(): void
    {
        $token = $this->registerAndGetToken();
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
                'name' => 'Morning Run',
                'frequency' => 'daily',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(201);

        /** @var array{id: string, name: string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('id', $data);
        self::assertArrayHasKey('name', $data);
        self::assertSame('Morning Run', $data['name']);
    }

    public function testListHabitsReturnsTwoHabitsSortedBySortOrder(): void
    {
        $token = $this->registerAndGetToken();

        $this->createHabit($token, 'First Habit', 0);
        $this->createHabit($token, 'Second Habit', 1);

        $client = self::createClient();
        $client->request(
            Request::METHOD_GET,
            '/api/v1/habits',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
        );

        self::assertResponseStatusCodeSame(200);

        /** @var array{habits: list<array{name: string, sort_order: int}>} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('habits', $data);
        self::assertCount(2, $data['habits']);
        self::assertLessThanOrEqual($data['habits'][1]['sort_order'], $data['habits'][0]['sort_order']);
    }

    public function testUpdateHabitChangesName(): void
    {
        $token = $this->registerAndGetToken();
        $habit = $this->createHabit($token);

        $client = self::createClient();
        $client->request(
            Request::METHOD_PUT,
            '/api/v1/habits/' . $habit['id'],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'name' => 'Updated Habit Name',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);

        /** @var array{name: string} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Updated Habit Name', $data['name']);
    }

    public function testDeleteHabitSoftDeletesItFromList(): void
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
            '/api/v1/habits',
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

    public function testReorderChangesSortOrder(): void
    {
        $token = $this->registerAndGetToken();
        $habitA = $this->createHabit($token, 'Habit A', 0);
        $habitB = $this->createHabit($token, 'Habit B', 1);

        $client = self::createClient();
        $client->request(
            Request::METHOD_PATCH,
            '/api/v1/habits/reorder',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
            json_encode([
                'order' => [
                    [
                        'id' => $habitA['id'],
                        'sort_order' => 5,
                    ],
                    [
                        'id' => $habitB['id'],
                        'sort_order' => 2,
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
        self::ensureKernelShutdown();

        $client = self::createClient();
        $client->request(
            Request::METHOD_GET,
            '/api/v1/habits',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            ],
        );

        /** @var array{habits: list<array{id: string, sort_order: int}>} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $byId = [];
        foreach ($data['habits'] as $h) {
            $byId[$h['id']] = $h['sort_order'];
        }

        self::assertArrayHasKey($habitA['id'], $byId);
        self::assertArrayHasKey($habitB['id'], $byId);
        self::assertSame(5, $byId[$habitA['id']]);
        self::assertSame(2, $byId[$habitB['id']]);
    }

    public function testHouseholdIsolationUserBCannotSeeUserAHabits(): void
    {
        $tokenA = $this->registerAndGetToken();
        $habitA = $this->createHabit($tokenA, 'User A Habit');

        $tokenB = $this->registerAndGetToken();

        $client = self::createClient();
        $client->request(
            Request::METHOD_GET,
            '/api/v1/habits',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenB,
            ],
        );

        self::assertResponseStatusCodeSame(200);

        /** @var array{habits: list<array{id: string}>} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $ids = array_column($data['habits'], 'id');

        self::assertNotContains($habitA['id'], $ids);
    }

    public function testHouseholdIsolationUserBCannotUpdateUserAHabit(): void
    {
        $tokenA = $this->registerAndGetToken();
        $habitA = $this->createHabit($tokenA, 'User A Private Habit');

        $tokenB = $this->registerAndGetToken();

        $client = self::createClient();
        $client->request(
            Request::METHOD_PUT,
            '/api/v1/habits/' . $habitA['id'],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $tokenB,
            ],
            json_encode([
                'name' => 'Hijacked',
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
     * @return array{id: string, name: string, frequency: string, sort_order: int}
     */
    private function createHabit(string $token, string $name = 'Test Habit', int $sortOrder = 0): array
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
                'sort_order' => $sortOrder,
            ], JSON_THROW_ON_ERROR),
        );

        /** @var array{id: string, name: string, frequency: string, sort_order: int} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::ensureKernelShutdown();

        return $data;
    }
}
