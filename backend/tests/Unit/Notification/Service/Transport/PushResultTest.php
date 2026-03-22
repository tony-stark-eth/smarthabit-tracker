<?php

declare(strict_types=1);

namespace Tests\Unit\Notification\Service\Transport;

use App\Notification\Service\Transport\PushResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PushResult::class)]
final class PushResultTest extends TestCase
{
    public function testDirectConstructionWithDefaults(): void
    {
        $result = new PushResult(success: true);

        self::assertTrue($result->success);
        self::assertNull($result->statusCode);
        self::assertNull($result->reason);
        self::assertFalse($result->shouldRemoveSubscription);
    }

    public function testDirectConstructionWithAllParams(): void
    {
        $result = new PushResult(
            success: false,
            statusCode: 400,
            reason: 'Bad request',
            shouldRemoveSubscription: true,
        );

        self::assertFalse($result->success);
        self::assertSame(400, $result->statusCode);
        self::assertSame('Bad request', $result->reason);
        self::assertTrue($result->shouldRemoveSubscription);
    }

    public function testSuccessFactoryWithNoStatusCode(): void
    {
        $result = PushResult::success();

        self::assertTrue($result->success);
        self::assertNull($result->statusCode);
        self::assertNull($result->reason);
        self::assertFalse($result->shouldRemoveSubscription);
    }

    public function testSuccessFactoryWithStatusCode(): void
    {
        $result = PushResult::success(201);

        self::assertTrue($result->success);
        self::assertSame(201, $result->statusCode);
        self::assertNull($result->reason);
        self::assertFalse($result->shouldRemoveSubscription);
    }

    public function testFailureFactory(): void
    {
        $result = PushResult::failure(500, 'Internal Server Error');

        self::assertFalse($result->success);
        self::assertSame(500, $result->statusCode);
        self::assertSame('Internal Server Error', $result->reason);
        self::assertFalse($result->shouldRemoveSubscription);
    }

    public function testFailureFactoryWithShouldRemove(): void
    {
        $result = PushResult::failure(410, 'Gone', true);

        self::assertFalse($result->success);
        self::assertSame(410, $result->statusCode);
        self::assertSame('Gone', $result->reason);
        self::assertTrue($result->shouldRemoveSubscription);
    }

    public function testShouldRemoveSubscriptionDefaultsToFalseOnFailure(): void
    {
        $result = PushResult::failure(400, 'Bad request');

        self::assertFalse($result->shouldRemoveSubscription);
    }
}
