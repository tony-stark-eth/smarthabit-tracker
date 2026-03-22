<?php

declare(strict_types=1);

namespace App\Notification\Service\Transport;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class NtfyTransport implements PushTransportInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $ntfyServerUrl,
    ) {
    }

    public function supports(string $type): bool
    {
        return $type === 'ntfy';
    }

    /**
     * @param array<string, mixed> $subscription
     */
    public function send(array $subscription, PushPayload $payload): PushResult
    {
        assert(isset($subscription['topic']) && \is_string($subscription['topic']));
        $topic = $subscription['topic'];
        $url = \sprintf('%s/%s', \rtrim($this->ntfyServerUrl, '/'), $topic);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Title' => $payload->title,
                    'Priority' => 'default',
                    'Tags' => 'habit',
                    'X-Actions' => \sprintf(
                        'view, Open SmartHabit, https://smarthabit.de/?log=%s',
                        $payload->habitId,
                    ),
                ],
                'body' => $payload->body,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                return PushResult::success(200);
            }

            if ($statusCode === 404) {
                return PushResult::failure(404, 'Topic not found', shouldRemove: true);
            }

            if ($statusCode >= 500) {
                return PushResult::failure($statusCode, \sprintf('Server error: %d', $statusCode));
            }

            return PushResult::failure($statusCode, \sprintf('Unexpected status: %d', $statusCode));
        } catch (TransportExceptionInterface $e) {
            return PushResult::failure(0, $e->getMessage());
        }
    }
}
