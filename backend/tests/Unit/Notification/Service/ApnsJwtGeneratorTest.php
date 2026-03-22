<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Service;

use App\Notification\Service\ApnsJwtGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApnsJwtGenerator::class)]
final class ApnsJwtGeneratorTest extends TestCase
{
    private string $privateKeyPath;

    protected function setUp(): void
    {
        $key = \openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => \OPENSSL_KEYTYPE_EC,
        ]);

        if ($key === false) {
            self::fail('Failed to generate EC key for testing');
        }

        \openssl_pkey_export($key, $pem);

        $this->privateKeyPath = \tempnam(\sys_get_temp_dir(), 'apns_test_key_') . '.pem';
        \file_put_contents($this->privateKeyPath, $pem);
    }

    protected function tearDown(): void
    {
        if (\file_exists($this->privateKeyPath)) {
            \unlink($this->privateKeyPath);
        }
    }

    public function testGenerateReturnsThreePartJwt(): void
    {
        $generator = new ApnsJwtGenerator(
            teamId: 'TEAMID1234',
            keyId: 'KEYID12345',
            privateKeyPath: $this->privateKeyPath,
        );

        $token = $generator->generate();

        $parts = \explode('.', $token);
        self::assertCount(3, $parts, 'JWT must have exactly 3 parts separated by dots');
    }

    public function testGenerateJwtHeaderContainsAlgAndKid(): void
    {
        $generator = new ApnsJwtGenerator(
            teamId: 'TEAMID1234',
            keyId: 'KEYID12345',
            privateKeyPath: $this->privateKeyPath,
        );

        $token = $generator->generate();
        $parts = \explode('.', $token);

        self::assertArrayHasKey(0, $parts);
        $headerJson = \base64_decode(\strtr($parts[0], '-_', '+/'), true);
        self::assertIsString($headerJson);
        /** @var array{alg: string, kid: string} $header */
        $header = \json_decode($headerJson, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('ES256', $header['alg']);
        self::assertSame('KEYID12345', $header['kid']);
    }

    public function testGenerateJwtClaimsContainIssAndIat(): void
    {
        $before = time();

        $generator = new ApnsJwtGenerator(
            teamId: 'TEAMID1234',
            keyId: 'KEYID12345',
            privateKeyPath: $this->privateKeyPath,
        );

        $token = $generator->generate();
        $after = time();

        $parts = \explode('.', $token);

        self::assertArrayHasKey(1, $parts);
        $claimsJson = \base64_decode(\strtr($parts[1], '-_', '+/'), true);
        self::assertIsString($claimsJson);
        /** @var array{iss: string, iat: int} $claims */
        $claims = \json_decode($claimsJson, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('TEAMID1234', $claims['iss']);
        self::assertGreaterThanOrEqual($before, $claims['iat']);
        self::assertLessThanOrEqual($after, $claims['iat']);
    }

    public function testGenerateReturnsCachedTokenOnSecondCall(): void
    {
        $generator = new ApnsJwtGenerator(
            teamId: 'TEAMID1234',
            keyId: 'KEYID12345',
            privateKeyPath: $this->privateKeyPath,
        );

        $firstToken = $generator->generate();
        $secondToken = $generator->generate();

        self::assertSame($firstToken, $secondToken, 'Second call must return the same cached token');
    }

    public function testGenerateJwtSignatureIsNonEmpty(): void
    {
        $generator = new ApnsJwtGenerator(
            teamId: 'TEAMID1234',
            keyId: 'KEYID12345',
            privateKeyPath: $this->privateKeyPath,
        );

        $token = $generator->generate();
        $parts = \explode('.', $token);

        self::assertArrayHasKey(2, $parts);
        self::assertNotEmpty($parts[2], 'JWT signature must not be empty');
    }

    public function testGenerateThrowsRuntimeExceptionWhenKeyFileDoesNotExist(): void
    {
        $generator = new ApnsJwtGenerator(
            teamId: 'TEAMID1234',
            keyId: 'KEYID12345',
            privateKeyPath: '/nonexistent/path/key.pem',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to read private key file/');
        $generator->generate();
    }
}
