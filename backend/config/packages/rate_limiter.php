<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $isTest = $container->env() === 'test';

    $container->extension('framework', [
        'rate_limiter' => [
            'login' => [
                'policy' => 'sliding_window',
                'limit' => $isTest ? 10000 : 5,
                'interval' => '1 minute',
            ],
            'register' => [
                'policy' => 'fixed_window',
                'limit' => $isTest ? 10000 : 3,
                'interval' => '15 minutes',
            ],
            'password_forgot' => [
                'policy' => 'fixed_window',
                'limit' => $isTest ? 10000 : 3,
                'interval' => '15 minutes',
            ],
            'api_general' => [
                'policy' => 'sliding_window',
                'limit' => $isTest ? 10000 : 60,
                'interval' => '1 minute',
            ],
        ],
    ]);
};
