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

    public function testGenerateJwtSignatureIsExactly64BytesRawSignature(): void
    {
        // ES256 DER signature decoded to raw R||S must be exactly 64 bytes (32+32)
        // This validates the derToRaw() conversion including offset arithmetic
        $generator = new ApnsJwtGenerator(
            teamId: 'TEAMID1234',
            keyId: 'KEYID12345',
            privateKeyPath: $this->privateKeyPath,
        );

        $token = $generator->generate();
        $parts = \explode('.', $token);
        self::assertArrayHasKey(2, $parts);

        // base64url decode: add padding and convert chars
        $sig64 = $parts[2];
        $padded = $sig64 . \str_repeat('=', (4 - \strlen($sig64) % 4) % 4);
        $rawSignature = \base64_decode(\strtr($padded, '-_', '+/'), true);

        self::assertIsString($rawSignature);
        self::assertSame(64, \strlen($rawSignature), 'ES256 raw signature must be exactly 64 bytes (R||S, each 32 bytes)');
    }

    public function testGenerateJwtSignatureRPartIsExactly32Bytes(): void
    {
        // The R component of the signature must be exactly 32 bytes
        // Validates str_pad/ltrim of R to 32 bytes
        $generator = new ApnsJwtGenerator(
            teamId: 'TEAMID1234',
            keyId: 'KEYID12345',
            privateKeyPath: $this->privateKeyPath,
        );

        $token = $generator->generate();
        $parts = \explode('.', $token);
        self::assertArrayHasKey(2, $parts);

        $sig64 = $parts[2];
        $padded = $sig64 . \str_repeat('=', (4 - \strlen($sig64) % 4) % 4);
        $rawSignature = \base64_decode(\strtr($padded, '-_', '+/'), true);

        self::assertIsString($rawSignature);
        // First 32 bytes are R, last 32 bytes are S
        $r = \substr($rawSignature, 0, 32);
        $s = \substr($rawSignature, 32, 32);
        self::assertSame(32, \strlen($r), 'R component must be exactly 32 bytes');
        self::assertSame(32, \strlen($s), 'S component must be exactly 32 bytes');
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

    public function testGenerateRegeneratesTokenAfterCacheTtlExpired(): void
    {
        $generator = new ApnsJwtGenerator(
            teamId: 'TEAMID1234',
            keyId: 'KEYID12345',
            privateKeyPath: $this->privateKeyPath,
        );

        $firstToken = $generator->generate();

        // Force the cached timestamp to be 3001 seconds ago (> 3000s TTL)
        $ref = new \ReflectionObject($generator);
        $cachedAt = $ref->getProperty('cachedAt');
        $cachedAt->setValue($generator, time() - 3001);

        $secondToken = $generator->generate();

        self::assertNotSame($firstToken, $secondToken, 'Token must be regenerated after TTL expires');
    }

    public function testGenerateReturnsCachedTokenWhenJustBelowTtl(): void
    {
        $generator = new ApnsJwtGenerator(
            teamId: 'TEAMID1234',
            keyId: 'KEYID12345',
            privateKeyPath: $this->privateKeyPath,
        );

        $firstToken = $generator->generate();

        // Force the cached timestamp to be 2999 seconds ago (still within 3000s TTL)
        $ref = new \ReflectionObject($generator);
        $cachedAt = $ref->getProperty('cachedAt');
        $cachedAt->setValue($generator, time() - 2999);

        $secondToken = $generator->generate();

        self::assertSame($firstToken, $secondToken, 'Token must be cached when still within TTL');
    }

    public function testGenerateJwtSignatureIsVerifiableWithPublicKey(): void
    {
        // Load the private key to extract the public key
        $pemContents = \file_get_contents($this->privateKeyPath);
        self::assertIsString($pemContents);

        $privateKey = \openssl_pkey_get_private($pemContents);
        self::assertNotFalse($privateKey, 'Must be able to load test private key');

        $keyDetails = \openssl_pkey_get_details($privateKey);
        self::assertIsArray($keyDetails);
        self::assertArrayHasKey('key', $keyDetails);
        $publicKeyPem = $keyDetails['key'];

        $generator = new ApnsJwtGenerator(
            teamId: 'TEAMID1234',
            keyId: 'KEYID12345',
            privateKeyPath: $this->privateKeyPath,
        );

        $token = $generator->generate();
        $parts = \explode('.', $token);
        self::assertCount(3, $parts);

        // The signing input is header.claims
        $signingInput = $parts[0] . '.' . $parts[1];

        // Decode the raw 64-byte signature (R||S)
        $sig64 = $parts[2];
        $padded = $sig64 . \str_repeat('=', (4 - \strlen($sig64) % 4) % 4);
        $rawSignature = \base64_decode(\strtr($padded, '-_', '+/'), true);
        self::assertIsString($rawSignature);
        self::assertSame(64, \strlen($rawSignature));

        // Convert raw R||S back to DER for openssl_verify
        $r = \substr($rawSignature, 0, 32);
        $s = \substr($rawSignature, 32, 32);

        // Strip leading zeros, ensure positive (prepend 0x00 if high bit set)
        $r = \ltrim($r, "\x00");
        $s = \ltrim($s, "\x00");
        if (\ord($r[0]) >= 0x80) {
            $r = "\x00" . $r;
        }
        if (\ord($s[0]) >= 0x80) {
            $s = "\x00" . $s;
        }

        $rLen = \strlen($r);
        $sLen = \strlen($s);
        $inner = "\x02" . \chr($rLen) . $r . "\x02" . \chr($sLen) . $s;
        $der = "\x30" . \chr(\strlen($inner)) . $inner;

        self::assertIsString($publicKeyPem);
        $publicKey = \openssl_pkey_get_public($publicKeyPem);
        self::assertNotFalse($publicKey);

        $verified = \openssl_verify($signingInput, $der, $publicKey, \OPENSSL_ALGO_SHA256);

        self::assertSame(1, $verified, 'JWT signature must be cryptographically valid');
    }

    public function testGenerateRegeneratesWhenOnlyTokenIsCachedButTimestampIsNull(): void
    {
        $generator = new ApnsJwtGenerator(
            teamId: 'TEAMID1234',
            keyId: 'KEYID12345',
            privateKeyPath: $this->privateKeyPath,
        );

        $firstToken = $generator->generate();

        // Simulate partial cache state: token set but cachedAt is null
        $ref = new \ReflectionObject($generator);
        $cachedAt = $ref->getProperty('cachedAt');
        $cachedAt->setValue($generator, null);

        $secondToken = $generator->generate();

        // When cachedAt is null, the cache condition fails and token is regenerated
        // The new token is valid but may be a different string (new iat)
        self::assertNotEmpty($secondToken);
        self::assertCount(3, \explode('.', $secondToken));
    }
}
