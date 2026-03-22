<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LoginController
{
    /**
     * This route is handled by the json_login firewall authenticator.
     * The method body is never executed — it exists only to register the route.
     */
    #[Route('/api/v1/login', name: 'api_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        return new JsonResponse([
            'message' => 'This should never be reached',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
