<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('mercure', [
        'hubs' => [
            'default' => [
                'url' => '%env(MERCURE_URL)%',
                'public_url' => '%env(MERCURE_PUBLIC_URL)%',
                'jwt' => [
                    'secret' => '%env(MERCURE_JWT_SECRET)%',
                    'algorithm' => 'hmac.sha256',
                    'publish' => ['*'],
                ],
            ],
        ],
    ]);
};
