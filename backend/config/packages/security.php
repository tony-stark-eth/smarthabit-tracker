<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('security', [
        'password_hashers' => [
            'Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface' => 'auto',
        ],
        'providers' => [
            'app_user_provider' => [
                'entity' => [
                    'class' => 'App\Auth\Entity\User',
                    'property' => 'email',
                ],
            ],
        ],
        'firewalls' => [
            'dev' => [
                'pattern' => '^/(_(profiler|wdt)|css|images|js)/',
                'security' => false,
            ],
            'login' => [
                'pattern' => '^/api/v1/login$',
                'stateless' => true,
                'json_login' => [
                    'check_path' => '/api/v1/login',
                    'username_path' => 'email',
                    'password_path' => 'password',
                    'success_handler' => 'lexik_jwt_authentication.handler.authentication_success',
                    'failure_handler' => 'lexik_jwt_authentication.handler.authentication_failure',
                ],
            ],
            'api' => [
                'pattern' => '^/api/',
                'stateless' => true,
                'jwt' => [],
            ],
            'main' => [
                'lazy' => true,
            ],
        ],
        'access_control' => [
            ['path' => '^/api/v1/register', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/api/v1/login', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/api/v1/health', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/api/v1/privacy', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/api/v1/password/(forgot|reset)', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/api/v1/', 'roles' => 'ROLE_USER'],
        ],
    ]);
};
