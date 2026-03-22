<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Service\Transport;

use App\Notification\Service\ApnsJwtGenerator;
use App\Notification\Service\Transport\ApnsTransport;
use App\Notification\Service\Transport\PushPayload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(ApnsTransport::class)]
final class ApnsTransportTest extends TestCase
{
    private const string BUNDLE_ID = 'com.example.smarthabit';

    private const string FIXED_JWT = 'header.claims.signature';

    private PushPayload $payload;

    private ApnsJwtGenerator $jwtGenerator;

    protected function setUp(): void
    {
        $this->payload = new PushPayload(
            title: 'Morning Run',
            body: 'Time to complete your habit!',
            habitId: 'habit-uuid-456',
        );

        $jwtGenerator = self::createStub(ApnsJwtGenerator::class);
        $jwtGenerator->method('generate')->willReturn(self::FIXED_JWT);
        $this->jwtGenerator = $jwtGenerator;
    }

    public function testSupportsReturnsTrueForApns(): void
    {
        $transport = new ApnsTransport(
            self::createStub(HttpClientInterface::class),
            $this->jwtGenerator,
            self::BUNDLE_ID,
        );

        self::assertTrue($transport->supports('apns'));
    }

    public function testSupportsReturnsFalseForOtherTypes(): void
    {
        $transport = new ApnsTransport(
            self::createStub(HttpClientInterface::class),
            $this->jwtGenerator,
            self::BUNDLE_ID,
        );

        self::assertFalse($transport->supports('ntfy'));
        self::assertFalse($transport->supports('web_push'));
        self::assertFalse($transport->supports(''));
    }

    public function testSendSuccessfulPushReturns200(): void
    {
        $subscription = [
            'device_token' => 'abc123devicetoken',
        ];

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                'https://api.sandbox.push.apple.com/3/device/abc123devicetoken',
                self::callback(static function (array $options): bool {
                    /** @var array{headers: array{Authorization: string, apns-topic: string, apns-push-type: string, Content-Type: string}, body: string} $options */
                    return $options['headers']['Authorization'] === 'Bearer ' . self::FIXED_JWT
                        && $options['headers']['apns-topic'] === self::BUNDLE_ID
                        && $options['headers']['apns-push-type'] === 'alert'
                        && $options['headers']['Content-Type'] === 'application/json'
                        && \str_contains($options['body'], 'Morning Run')
                        && \str_contains($options['body'], 'habit-uuid-456');
                }),
            )
            ->willReturn($response);

        $transport = new ApnsTransport($httpClient, $this->jwtGenerator, self::BUNDLE_ID);
        $result = $transport->send($subscription, $this->payload);

        self::assertTrue($result->success);
        self::assertSame(200, $result->statusCode);
        self::assertFalse($result->shouldRemoveSubscription);
    }

    public function testSendUsesProductionUrlWhenEnvironmentIsProduction(): void
    {
        $subscription = [
            'device_token' => 'abc123devicetoken',
        ];

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                'https://api.push.apple.com/3/device/abc123devicetoken',
                self::anything(),
            )
            ->willReturn($response);

        $transport = new ApnsTransport($httpClient, $this->jwtGenerator, self::BUNDLE_ID, 'production');
        $transport->send($subscription, $this->payload);
    }

    public function testSend410ReturnsShouldRemoveSubscription(): void
    {
        $subscription = [
            'device_token' => 'expired-token',
        ];

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(410);

        $httpClient = self::createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $transport = new ApnsTransport($httpClient, $this->jwtGenerator, self::BUNDLE_ID);
        $result = $transport->send($subscription, $this->payload);

        self::assertFalse($result->success);
        self::assertSame(410, $result->statusCode);
        self::assertTrue($result->shouldRemoveSubscription);
        self::assertSame('Unregistered', $result->reason);
    }

    public function testSendOtherErrorReturnsFailure(): void
    {
        $subscription = [
            'device_token' => 'some-token',
        ];

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(400);
        $response->method('getContent')->willReturn('{"reason":"BadDeviceToken"}');

        $httpClient = self::createStub(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        $transport = new ApnsTransport($httpClient, $this->jwtGenerator, self::BUNDLE_ID);
        $result = $transport->send($subscription, $this->payload);

        self::assertFalse($result->success);
        self::assertSame(400, $result->statusCode);
        self::assertFalse($result->shouldRemoveSubscription);
        self::assertNotNull($result->reason);
    }

    public function testSendTransportExceptionReturnsFailureWithZeroStatusCode(): void
    {
        $subscription = [
            'device_token' => 'some-token',
        ];

        $exception = new class('Connection refused') extends \RuntimeException implements TransportExceptionInterface {};

        $httpClient = self::createStub(HttpClientInterface::class);
        $httpClient
            ->method('request')
            ->willThrowException($exception);

        $transport = new ApnsTransport($httpClient, $this->jwtGenerator, self::BUNDLE_ID);
        $result = $transport->send($subscription, $this->payload);

        self::assertFalse($result->success);
        self::assertSame(0, $result->statusCode);
        self::assertSame('Connection refused', $result->reason);
        self::assertFalse($result->shouldRemoveSubscription);
    }

    public function testSendIncludesHabitIdInRequestBody(): void
    {
        $subscription = [
            'device_token' => 'some-token',
        ];

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        /** @var array{body: string} $capturedOptions */
        $capturedOptions = [];
        $httpClient = self::createStub(HttpClientInterface::class);
        $httpClient
            ->method('request')
            ->willReturnCallback(
                static function (string $method, string $url, array $options) use (&$capturedOptions, $response): ResponseInterface {
                    $capturedOptions = $options;

                    return $response;
                },
            );

        $transport = new ApnsTransport($httpClient, $this->jwtGenerator, self::BUNDLE_ID);
        $transport->send($subscription, $this->payload);

        self::assertArrayHasKey('body', $capturedOptions);
        self::assertIsString($capturedOptions['body']);
        /** @var array{habitId: string, aps: array{alert: array{title: string, body: string}, sound: string}} $body */
        $body = \json_decode($capturedOptions['body'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('habit-uuid-456', $body['habitId']);
        self::assertSame('Morning Run', $body['aps']['alert']['title']);
        self::assertSame('Time to complete your habit!', $body['aps']['alert']['body']);
        self::assertSame('default', $body['aps']['sound']);
    }
}
