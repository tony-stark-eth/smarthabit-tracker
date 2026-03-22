<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Service\Transport;

use App\Notification\Service\Transport\NtfyTransport;
use App\Notification\Service\Transport\PushPayload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(NtfyTransport::class)]
final class NtfyTransportTest extends TestCase
{
    private const string SERVER_URL = 'https://ntfy.example.com';

    private PushPayload $payload;

    protected function setUp(): void
    {
        $this->payload = new PushPayload(
            title: 'Test Habit',
            body: 'Time to log your habit!',
            habitId: 'habit-uuid-123',
        );
    }

    public function testSupportsReturnsTrueForNtfy(): void
    {
        $transport = new NtfyTransport(
            self::createStub(HttpClientInterface::class),
            self::SERVER_URL,
        );

        self::assertTrue($transport->supports('ntfy'));
    }

    public function testSupportsReturnsFalseForOtherTypes(): void
    {
        $transport = new NtfyTransport(
            self::createStub(HttpClientInterface::class),
            self::SERVER_URL,
        );

        self::assertFalse($transport->supports('webpush'));
        self::assertFalse($transport->supports('apns'));
        self::assertFalse($transport->supports(''));
    }

    public function testSendSuccessfulPost(): void
    {
        $subscription = [
            'topic' => 'my-topic',
        ];

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                self::SERVER_URL . '/my-topic',
                self::callback(static function (array $options): bool {
                    /** @var array{headers: array{Title: string, Priority: string, Tags: string, 'X-Actions': string}, body: string} $options */
                    return $options['headers']['Title'] === 'Test Habit'
                        && $options['headers']['Priority'] === 'default'
                        && $options['headers']['Tags'] === 'habit'
                        && \str_contains($options['headers']['X-Actions'], 'habit-uuid-123')
                        && $options['body'] === 'Time to log your habit!';
                }),
            )
            ->willReturn($response);

        $transport = new NtfyTransport($httpClient, self::SERVER_URL);
        $result = $transport->send($subscription, $this->payload);

        self::assertTrue($result->success);
        self::assertSame(200, $result->statusCode);
        self::assertFalse($result->shouldRemoveSubscription);
    }

    public function testSendStripsTrailingSlashFromServerUrl(): void
    {
        $subscription = [
            'topic' => 'my-topic',
        ];

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->expects(self::once())
            ->method('request')
            ->with('POST', self::SERVER_URL . '/my-topic', self::anything())
            ->willReturn($response);

        $transport = new NtfyTransport($httpClient, self::SERVER_URL . '/');
        $transport->send($subscription, $this->payload);
    }

    public function testSend404ReturnsShouldRemoveSubscription(): void
    {
        $subscription = [
            'topic' => 'missing-topic',
        ];

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);

        $httpClient = self::createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $transport = new NtfyTransport($httpClient, self::SERVER_URL);
        $result = $transport->send($subscription, $this->payload);

        self::assertFalse($result->success);
        self::assertSame(404, $result->statusCode);
        self::assertTrue($result->shouldRemoveSubscription);
        self::assertNotNull($result->reason);
    }

    public function testSend5xxReturnsFailure(): void
    {
        $subscription = [
            'topic' => 'my-topic',
        ];

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(503);

        $httpClient = self::createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $transport = new NtfyTransport($httpClient, self::SERVER_URL);
        $result = $transport->send($subscription, $this->payload);

        self::assertFalse($result->success);
        self::assertSame(503, $result->statusCode);
        self::assertFalse($result->shouldRemoveSubscription);
        self::assertNotNull($result->reason);
    }

    public function testSend500ExactlyIsHandledAsServerError(): void
    {
        // Status 500 must match `>= 500` (not `> 500`), returning 'Server error' message
        $subscription = [
            'topic' => 'my-topic',
        ];

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(500);

        $httpClient = self::createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $transport = new NtfyTransport($httpClient, self::SERVER_URL);
        $result = $transport->send($subscription, $this->payload);

        self::assertFalse($result->success);
        self::assertSame(500, $result->statusCode);
        self::assertFalse($result->shouldRemoveSubscription);
        self::assertStringContainsString('500', (string) $result->reason);
        self::assertStringContainsString('Server error', (string) $result->reason);
    }

    public function testSend5xxMessageDiffersFromUnexpectedStatus(): void
    {
        // Status 500 returns 'Server error: 500', distinguishable from 'Unexpected status: 400'
        $subscription = [
            'topic' => 'my-topic',
        ];

        $responseServer = self::createStub(ResponseInterface::class);
        $responseServer->method('getStatusCode')->willReturn(500);

        $responseUnexpected = self::createStub(ResponseInterface::class);
        $responseUnexpected->method('getStatusCode')->willReturn(400);

        $httpClient1 = self::createStub(HttpClientInterface::class);
        $httpClient1->method('request')->willReturn($responseServer);

        $httpClient2 = self::createStub(HttpClientInterface::class);
        $httpClient2->method('request')->willReturn($responseUnexpected);

        $transport1 = new NtfyTransport($httpClient1, self::SERVER_URL);
        $transport2 = new NtfyTransport($httpClient2, self::SERVER_URL);

        $result500 = $transport1->send($subscription, $this->payload);
        $result400 = $transport2->send($subscription, $this->payload);

        self::assertNotSame($result500->reason, $result400->reason);
        self::assertStringContainsString('Server error', (string) $result500->reason);
        self::assertStringContainsString('Unexpected status', (string) $result400->reason);
    }

    public function testSendTransportExceptionReturnsFailure(): void
    {
        $subscription = [
            'topic' => 'my-topic',
        ];

        $exception = new class('Network error') extends \RuntimeException implements TransportExceptionInterface {};

        $httpClient = self::createStub(HttpClientInterface::class);
        $httpClient
            ->method('request')
            ->willThrowException($exception);

        $transport = new NtfyTransport($httpClient, self::SERVER_URL);
        $result = $transport->send($subscription, $this->payload);

        self::assertFalse($result->success);
        self::assertSame(0, $result->statusCode);
        self::assertSame('Network error', $result->reason);
        self::assertFalse($result->shouldRemoveSubscription);
    }

    public function testSendIncludesXActionsHeaderWithHabitId(): void
    {
        $subscription = [
            'topic' => 'my-topic',
        ];

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        /** @var array{headers: array<string, string>} $capturedOptions */
        $capturedOptions = [];
        $httpClient = self::createStub(HttpClientInterface::class);
        $httpClient
            ->method('request')
            ->willReturnCallback(static function (string $method, string $url, array $options) use (&$capturedOptions, $response): ResponseInterface {
                $capturedOptions = $options;

                return $response;
            });

        $transport = new NtfyTransport($httpClient, self::SERVER_URL);
        $transport->send($subscription, $this->payload);

        self::assertArrayHasKey('headers', $capturedOptions);
        self::assertIsArray($capturedOptions['headers']);
        self::assertArrayHasKey('X-Actions', $capturedOptions['headers']);
        self::assertIsString($capturedOptions['headers']['X-Actions']);
        self::assertStringContainsString('habit-uuid-123', $capturedOptions['headers']['X-Actions']);
        self::assertStringContainsString('https://smarthabit.de/?log=', $capturedOptions['headers']['X-Actions']);
    }
}
