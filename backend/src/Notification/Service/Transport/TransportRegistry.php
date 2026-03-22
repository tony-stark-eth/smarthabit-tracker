<?php

declare(strict_types=1);

namespace App\Notification\Service\Transport;

final readonly class TransportRegistry
{
    /**
     * @param iterable<PushTransportInterface> $transports
     */
    public function __construct(
        private iterable $transports,
    ) {
    }

    public function getTransport(string $type): ?PushTransportInterface
    {
        foreach ($this->transports as $transport) {
            if ($transport->supports($type)) {
                return $transport;
            }
        }

        return null;
    }
}
