<?php

declare(strict_types=1);

namespace Tests\Integration\Habit;

use App\Auth\Entity\User;
use App\Habit\Controller\DashboardController;
use App\Habit\Entity\Habit;
use App\Habit\Entity\HabitLog;
use App\Habit\Enum\HabitLogSource;
use Doctrine\ORM\EntityManager;
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

    public function testDashboardIsDoneTodayRespectsUserTimezone(): void
    {
        $email = uniqid('test_', true) . '@example.com';
        $token = $this->registerAndGetToken($email, 'Pacific/Auckland');
        $habit = $this->createHabit($token, 'Auckland Habit');

        // Compute a UTC timestamp that is "today" in Auckland but "yesterday" in UTC.
        // Auckland is UTC+12 (NZST) or UTC+13 (NZDT). Taking local 00:30 and converting
        // to UTC always lands on the previous UTC calendar day.
        $aucklandTz = new \DateTimeZone('Pacific/Auckland');
        $utcTz = new \DateTimeZone('UTC');
        $nowAuckland = new \DateTimeImmutable('now', $aucklandTz);
        $loggedAtUtc = $nowAuckland->setTime(0, 30, 0)->setTimezone($utcTz);

        /** @var EntityManager $em */
        $em = self::getContainer()->get('doctrine.orm.entity_manager');

        /** @var User|null $user */
        $user = $em->getRepository(User::class)->findOneBy([
            'email' => $email,
        ]);
        self::assertInstanceOf(User::class, $user);

        /** @var Habit|null $habitEntity */
        $habitEntity = $em->getRepository(Habit::class)->findOneBy([
            'id' => $habit['id'],
        ]);
        self::assertInstanceOf(Habit::class, $habitEntity);

        $log = new HabitLog($habitEntity, $user, $loggedAtUtc, HabitLogSource::MANUAL);
        $em->persist($log);
        $em->flush();

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

        /** @var array{habits: list<array{id: string, is_done_today: bool}>} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $found = false;
        foreach ($data['habits'] as $h) {
            if ($h['id'] === $habit['id']) {
                self::assertTrue($h['is_done_today'], 'Log logged at 00:30 local (Auckland) must count as done today.');
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Habit was not found in dashboard response.');
    }

    public function testDashboardIsDoneTodayDoesNotLeakAcrossTimezones(): void
    {
        $email = uniqid('test_', true) . '@example.com';
        $token = $this->registerAndGetToken($email, 'America/Los_Angeles');
        $habit = $this->createHabit($token, 'LA Habit');
        $this->createLog($token, $habit['id']);

        // Rewrite the log's logged_at to a UTC timestamp that is "today" in UTC
        // but "yesterday" in America/Los_Angeles.
        // LA is UTC-7 (PDT) or UTC-8 (PST). UTC 01:00 = 18:00 or 17:00 previous day in LA.
        $utcTz = new \DateTimeZone('UTC');
        $nowUtc = new \DateTimeImmutable('now', $utcTz);
        $loggedAtUtc = $nowUtc->setTime(1, 0, 0);

        /** @var EntityManager $em */
        $em = self::getContainer()->get('doctrine.orm.entity_manager');

        /** @var User|null $user */
        $user = $em->getRepository(User::class)->findOneBy([
            'email' => $email,
        ]);
        self::assertInstanceOf(User::class, $user);

        /** @var Habit|null $habitEntity */
        $habitEntity = $em->getRepository(Habit::class)->findOneBy([
            'id' => $habit['id'],
        ]);
        self::assertInstanceOf(Habit::class, $habitEntity);

        /** @var list<HabitLog> $logs */
        $logs = $em->getRepository(HabitLog::class)->findBy([
            'habit' => $habitEntity,
            'user' => $user,
        ]);
        self::assertCount(1, $logs);

        // Use DQL UPDATE to bypass the read-only constructor and rewrite logged_at.
        $em->createQuery(
            'UPDATE ' . HabitLog::class . ' hl SET hl.loggedAt = :loggedAt WHERE hl.id = :id',
        )
            ->setParameter('loggedAt', $loggedAtUtc)
            ->setParameter('id', $logs[0]->getId())
            ->execute();

        $em->clear();

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

        /** @var array{habits: list<array{id: string, is_done_today: bool}>} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $found = false;
        foreach ($data['habits'] as $h) {
            if ($h['id'] === $habit['id']) {
                self::assertFalse($h['is_done_today'], 'Log at UTC 01:00 must not count as done today in LA timezone.');
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Habit was not found in dashboard response.');
    }

    private function registerAndGetToken(?string $email = null, string $timezone = 'Europe/Berlin'): string
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
                'timezone' => $timezone,
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
