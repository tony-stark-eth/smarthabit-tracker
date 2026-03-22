<?php

declare(strict_types=1);

use App\Notification\Controller\PushSubscriptionController;
use App\Notification\Service\WebPushService;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services
        ->set(WebPushService::class)
        ->autowire()
        ->arg('$vapidPublicKey', '%env(VAPID_PUBLIC_KEY)%')
        ->arg('$vapidPrivateKey', '%env(VAPID_PRIVATE_KEY)%');

    $services
        ->set(PushSubscriptionController::class)
        ->autoconfigure()
        ->autowire()
        ->arg('$vapidPublicKey', '%env(VAPID_PUBLIC_KEY)%');
};
