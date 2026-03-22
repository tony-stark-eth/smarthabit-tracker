<?php

declare(strict_types=1);

namespace Tests\Unit\Notification\Service\Transport;

use App\Notification\Service\Transport\PushPayload;
use App\Notification\Service\Transport\WebPushTransport;
use App\Notification\Service\WebPushServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebPushTransport::class)]
final class WebPushTransportTest extends TestCase
{
    public function testSupportsReturnsTrueForWebPush(): void
    {
        /** @var WebPushServiceInterface&Stub $service */
        $service = self::createStub(WebPushServiceInterface::class);
        $transport = new WebPushTransport($service);

        self::assertTrue($transport->supports('web_push'));
    }

    public function testSupportsReturnsFalseForOtherTypes(): void
    {
        /** @var WebPushServiceInterface&Stub $service */
        $service = self::createStub(WebPushServiceInterface::class);
        $transport = new WebPushTransport($service);

        self::assertFalse($transport->supports('ntfy'));
        self::assertFalse($transport->supports('apns'));
        self::assertFalse($transport->supports(''));
        self::assertFalse($transport->supports('WEB_PUSH'));
    }

    public function testSendDelegatesToWebPushServiceAndMapsSuccess(): void
    {
        $subscription = [
            'endpoint' => 'https://push.example.com/endpoint',
            'keys' => [
                'p256dh' => 'p256dh-key',
                'auth' => 'auth-key',
            ],
        ];
        $payload = new PushPayload(
            title: 'Test Habit',
            body: 'Time to do it!',
            habitId: 'habit-123',
        );

        /** @var WebPushServiceInterface&MockObject $service */
        $service = $this->createMock(WebPushServiceInterface::class);
        $service
            ->expects(self::once())
            ->method('send')
            ->with($subscription, self::anything())
            ->willReturn([
                'success' => true,
                'statusCode' => 201,
                'reason' => null,
            ]);

        $transport = new WebPushTransport($service);
        $result = $transport->send($subscription, $payload);

        self::assertTrue($result->success);
        self::assertSame(201, $result->statusCode);
        self::assertNull($result->reason);
        self::assertFalse($result->shouldRemoveSubscription);
    }

    public function testSendMapsFailureResult(): void
    {
        $subscription = [
            'endpoint' => 'https://push.example.com/endpoint',
            'keys' => [
                'p256dh' => 'p256dh-key',
                'auth' => 'auth-key',
            ],
        ];
        $payload = new PushPayload(
            title: 'Test Habit',
            body: 'Time to do it!',
            habitId: 'habit-123',
        );

        /** @var WebPushServiceInterface&MockObject $service */
        $service = $this->createMock(WebPushServiceInterface::class);
        $service
            ->expects(self::once())
            ->method('send')
            ->willReturn([
                'success' => false,
                'statusCode' => 500,
                'reason' => 'Internal Server Error',
            ]);

        $transport = new WebPushTransport($service);
        $result = $transport->send($subscription, $payload);

        self::assertFalse($result->success);
        self::assertSame(500, $result->statusCode);
        self::assertSame('Internal Server Error', $result->reason);
        self::assertFalse($result->shouldRemoveSubscription);
    }

    public function testSend410StatusCodeSetsShouldRemoveSubscription(): void
    {
        $subscription = [
            'endpoint' => 'https://push.example.com/endpoint',
            'keys' => [
                'p256dh' => 'p256dh-key',
                'auth' => 'auth-key',
            ],
        ];
        $payload = new PushPayload(
            title: 'Test Habit',
            body: 'Time to do it!',
            habitId: 'habit-123',
        );

        /** @var WebPushServiceInterface&MockObject $service */
        $service = $this->createMock(WebPushServiceInterface::class);
        $service
            ->expects(self::once())
            ->method('send')
            ->willReturn([
                'success' => false,
                'statusCode' => 410,
                'reason' => 'Gone',
            ]);

        $transport = new WebPushTransport($service);
        $result = $transport->send($subscription, $payload);

        self::assertFalse($result->success);
        self::assertSame(410, $result->statusCode);
        self::assertSame('Gone', $result->reason);
        self::assertTrue($result->shouldRemoveSubscription);
    }

    public function testSendEncodesPayloadAsJson(): void
    {
        $subscription = [
            'endpoint' => 'https://push.example.com/endpoint',
            'keys' => [
                'p256dh' => 'p256dh-key',
                'auth' => 'auth-key',
            ],
        ];
        $payload = new PushPayload(
            title: 'My Title',
            body: 'My Body',
            habitId: 'habit-abc',
        );

        $capturedJsonPayload = null;

        /** @var WebPushServiceInterface&MockObject $service */
        $service = $this->createMock(WebPushServiceInterface::class);
        $service
            ->expects(self::once())
            ->method('send')
            ->willReturnCallback(function (array $sub, string $json) use (&$capturedJsonPayload): array {
                $capturedJsonPayload = $json;

                return [
                    'success' => true,
                    'statusCode' => 201,
                    'reason' => null,
                ];
            });

        $transport = new WebPushTransport($service);
        $transport->send($subscription, $payload);

        self::assertNotNull($capturedJsonPayload);
        self::assertIsString($capturedJsonPayload);

        /** @var array{title: string, body: string, habitId: string} $decoded */
        $decoded = json_decode($capturedJsonPayload, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('My Title', $decoded['title']);
        self::assertSame('My Body', $decoded['body']);
        self::assertSame('habit-abc', $decoded['habitId']);
    }
}
