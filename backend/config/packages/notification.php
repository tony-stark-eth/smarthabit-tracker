<?php

declare(strict_types=1);

use App\Notification\Controller\PushSubscriptionController;
use App\Notification\Service\ApnsJwtGenerator;
use App\Notification\Service\Transport\ApnsTransport;
use App\Notification\Service\Transport\NtfyTransport;
use App\Notification\Service\Transport\PushTransportInterface;
use App\Notification\Service\Transport\TransportRegistry;
use App\Notification\Service\Transport\WebPushTransport;
use App\Notification\Service\WebPushService;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->instanceof(PushTransportInterface::class)
        ->tag('app.push_transport');

    $services
        ->set(WebPushService::class)
        ->autowire()
        ->arg('$vapidPublicKey', '%env(VAPID_PUBLIC_KEY)%')
        ->arg('$vapidPrivateKey', '%env(VAPID_PRIVATE_KEY)%');

    $services
        ->set(WebPushTransport::class)
        ->autowire()
        ->arg('$webPushService', WebPushService::class);

    $services
        ->set(NtfyTransport::class)
        ->autowire()
        ->arg('$ntfyServerUrl', '%env(NTFY_SERVER_URL)%');

    $services
        ->set(ApnsJwtGenerator::class)
        ->arg('$teamId', '%env(APNS_TEAM_ID)%')
        ->arg('$keyId', '%env(APNS_KEY_ID)%')
        ->arg('$privateKeyPath', '%env(APNS_PRIVATE_KEY_PATH)%');

    $services
        ->set(ApnsTransport::class)
        ->autowire()
        ->arg('$jwtGenerator', ApnsJwtGenerator::class)
        ->arg('$bundleId', '%env(APNS_BUNDLE_ID)%')
        ->arg('$environment', '%env(APNS_ENVIRONMENT)%');

    $services
        ->set(TransportRegistry::class)
        ->arg('$transports', tagged_iterator('app.push_transport'));

    $services
        ->set(PushSubscriptionController::class)
        ->autoconfigure()
        ->autowire()
        ->arg('$vapidPublicKey', '%env(VAPID_PUBLIC_KEY)%')
        ->arg('$ntfyServerUrl', '%env(NTFY_SERVER_URL)%');
};
