<?php

declare(strict_types=1);

namespace Tests\Unit\Notification\Service\Transport;

use App\Notification\Service\Transport\PushPayload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PushPayload::class)]
final class PushPayloadTest extends TestCase
{
    public function testConstructionAndPropertyAccess(): void
    {
        $payload = new PushPayload(
            title: 'Take your vitamins',
            body: 'Time to complete your habit!',
            habitId: 'habit-uuid-123',
        );

        self::assertSame('Take your vitamins', $payload->title);
        self::assertSame('Time to complete your habit!', $payload->body);
        self::assertSame('habit-uuid-123', $payload->habitId);
    }

    public function testEmptyStringsAreAllowed(): void
    {
        $payload = new PushPayload(
            title: '',
            body: '',
            habitId: '',
        );

        self::assertSame('', $payload->title);
        self::assertSame('', $payload->body);
        self::assertSame('', $payload->habitId);
    }
}
