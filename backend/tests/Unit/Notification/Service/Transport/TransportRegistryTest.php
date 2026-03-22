<?php

declare(strict_types=1);

namespace Tests\Unit\Notification\Service\Transport;

use App\Notification\Service\Transport\PushResult;
use App\Notification\Service\Transport\PushTransportInterface;
use App\Notification\Service\Transport\TransportRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TransportRegistry::class)]
final class TransportRegistryTest extends TestCase
{
    public function testGetTransportReturnsMatchingTransport(): void
    {
        $transport = $this->createTransportStub('web_push');
        $registry = new TransportRegistry([$transport]);

        self::assertSame($transport, $registry->getTransport('web_push'));
    }

    public function testGetTransportReturnsNullForUnsupportedType(): void
    {
        $transport = $this->createTransportStub('web_push');
        $registry = new TransportRegistry([$transport]);

        self::assertNull($registry->getTransport('ntfy'));
    }

    public function testGetTransportReturnsNullForEmptyRegistry(): void
    {
        $registry = new TransportRegistry([]);

        self::assertNull($registry->getTransport('web_push'));
    }

    public function testGetTransportReturnsFirstMatchingTransport(): void
    {
        $first = $this->createTransportStub('web_push');
        $second = $this->createTransportStub('web_push');
        $registry = new TransportRegistry([$first, $second]);

        self::assertSame($first, $registry->getTransport('web_push'));
    }

    public function testGetTransportSelectsCorrectTransportFromMultiple(): void
    {
        $webPush = $this->createTransportStub('web_push');
        $ntfy = $this->createTransportStub('ntfy');
        $apns = $this->createTransportStub('apns');
        $registry = new TransportRegistry([$webPush, $ntfy, $apns]);

        self::assertSame($webPush, $registry->getTransport('web_push'));
        self::assertSame($ntfy, $registry->getTransport('ntfy'));
        self::assertSame($apns, $registry->getTransport('apns'));
        self::assertNull($registry->getTransport('unknown'));
    }

    private function createTransportStub(string $supportedType): PushTransportInterface
    {
        $stub = self::createStub(PushTransportInterface::class);
        $stub->method('supports')->willReturnCallback(
            static fn (string $type): bool => $type === $supportedType,
        );
        $stub->method('send')->willReturn(PushResult::success());

        return $stub;
    }
}
