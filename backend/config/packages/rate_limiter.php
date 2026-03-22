<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $isProd = $container->env() === 'prod';

    $container->extension('framework', [
        'rate_limiter' => [
            'login' => [
                'policy' => 'sliding_window',
                'limit' => $isProd ? 5 : 10000,
                'interval' => '1 minute',
            ],
            'register' => [
                'policy' => 'fixed_window',
                'limit' => $isProd ? 3 : 10000,
                'interval' => '15 minutes',
            ],
            'password_forgot' => [
                'policy' => 'fixed_window',
                'limit' => $isProd ? 3 : 10000,
                'interval' => '15 minutes',
            ],
            'api_general' => [
                'policy' => 'sliding_window',
                'limit' => $isProd ? 60 : 10000,
                'interval' => '1 minute',
            ],
        ],
    ]);
};
