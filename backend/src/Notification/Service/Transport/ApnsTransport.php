<?php

declare(strict_types=1);

namespace App\Notification\Service\Transport;

use App\Notification\Service\ApnsJwtGenerator;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ApnsTransport implements PushTransportInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ApnsJwtGenerator $jwtGenerator,
        private string $bundleId,
        private string $environment = 'development',
    ) {
    }

    public function supports(string $type): bool
    {
        return $type === 'apns';
    }

    /**
     * @param array<string, mixed> $subscription
     */
    public function send(array $subscription, PushPayload $payload): PushResult
    {
        assert(isset($subscription['device_token']) && \is_string($subscription['device_token']));
        $deviceToken = $subscription['device_token'];

        $baseUrl = $this->environment === 'production'
            ? 'https://api.push.apple.com'
            : 'https://api.sandbox.push.apple.com';

        $url = \sprintf('%s/3/device/%s', $baseUrl, $deviceToken);

        $body = \json_encode([
            'aps' => [
                'alert' => [
                    'title' => $payload->title,
                    'body' => $payload->body,
                ],
                'sound' => 'default',
            ],
            'habitId' => $payload->habitId,
        ], \JSON_THROW_ON_ERROR);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => \sprintf('Bearer %s', $this->jwtGenerator->generate()),
                    'apns-topic' => $this->bundleId,
                    'apns-push-type' => 'alert',
                    'Content-Type' => 'application/json',
                ],
                'body' => $body,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                return PushResult::success(200);
            }

            if ($statusCode === 410) {
                return PushResult::failure(410, 'Unregistered', shouldRemove: true);
            }

            $reason = $this->extractReason($response->getContent(false), $statusCode);

            return PushResult::failure($statusCode, $reason);
        } catch (TransportExceptionInterface $e) {
            return PushResult::failure(0, $e->getMessage());
        }
    }

    private function extractReason(string $responseBody, int $statusCode): string
    {
        if ($responseBody === '') {
            return \sprintf('HTTP %d', $statusCode);
        }

        try {
            /** @var array{reason?: string} $data */
            $data = \json_decode($responseBody, true, 512, \JSON_THROW_ON_ERROR);

            return $data['reason'] ?? \sprintf('HTTP %d', $statusCode);
        } catch (\JsonException) {
            return \sprintf('HTTP %d', $statusCode);
        }
    }
}
