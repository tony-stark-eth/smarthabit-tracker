<?php

declare(strict_types=1);

namespace App\Notification\Service;

class ApnsJwtGenerator
{
    private const int CACHE_TTL_SECONDS = 3000; // 50 minutes

    private ?string $cachedToken = null;

    private ?int $cachedAt = null;

    public function __construct(
        private readonly string $teamId,
        private readonly string $keyId,
        private readonly string $privateKeyPath,
    ) {
    }

    public function generate(): string
    {
        $now = \Carbon\Carbon::now()->getTimestamp();

        if ($this->cachedToken !== null && $this->cachedAt !== null && ($now - $this->cachedAt) < self::CACHE_TTL_SECONDS) {
            return $this->cachedToken;
        }

        $header = $this->base64url(\json_encode([
            'alg' => 'ES256',
            'kid' => $this->keyId,
        ], \JSON_THROW_ON_ERROR));

        $claims = $this->base64url(\json_encode([
            'iss' => $this->teamId,
            'iat' => $now,
        ], \JSON_THROW_ON_ERROR));

        $signingInput = $header . '.' . $claims;

        $keyContents = @\file_get_contents($this->privateKeyPath);

        if ($keyContents === false) {
            throw new \RuntimeException(\sprintf('Failed to read private key file: %s', $this->privateKeyPath));
        }

        $privateKey = \openssl_pkey_get_private($keyContents);

        if ($privateKey === false) {
            throw new \RuntimeException(\sprintf('Failed to load private key from: %s', $this->privateKeyPath));
        }

        $signature = '';
        $result = \openssl_sign($signingInput, $signature, $privateKey, \OPENSSL_ALGO_SHA256);

        if ($result === false) {
            throw new \RuntimeException('Failed to sign JWT for APNs');
        }

        assert(\is_string($signature));
        $rawSignature = $this->derToRaw($signature);

        $token = $signingInput . '.' . $this->base64url($rawSignature);

        $this->cachedToken = $token;
        $this->cachedAt = $now;

        return $token;
    }

    private function base64url(string $data): string
    {
        return \strtr(\rtrim(\base64_encode($data), '='), '+/', '-_');
    }

    // DER signature → raw R||S (each 32 bytes, zero-padded)
    private function derToRaw(string $der): string
    {
        $offset = 3; // skip 0x30 + length byte + 0x02
        $rLen = \ord($der[$offset]);
        $r = \substr($der, $offset + 1, $rLen);
        $offset += 1 + $rLen + 1; // skip r + 0x02
        $sLen = \ord($der[$offset]);
        $s = \substr($der, $offset + 1, $sLen);
        // Pad/trim to 32 bytes each
        $r = \str_pad(\ltrim($r, "\x00"), 32, "\x00", \STR_PAD_LEFT);
        $s = \str_pad(\ltrim($s, "\x00"), 32, "\x00", \STR_PAD_LEFT);

        return $r . $s;
    }
}
