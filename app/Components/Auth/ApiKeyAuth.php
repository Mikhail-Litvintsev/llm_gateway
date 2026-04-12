<?php

declare(strict_types=1);

namespace App\Components\Auth;

use App\Components\Auth\Exceptions\AuthenticationException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApiKeyAuth
{
    public function __construct(
        private readonly Auth $auth,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization');

        if ($header === null) {
            return $this->unauthorized();
        }

        try {
            $client = $this->auth->authenticate($header);
        } catch (AuthenticationException) {
            return $this->unauthorized();
        }

        $request->attributes->set('client', $client);

        return $next($request);
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'type' => 'authentication_error',
                'message' => 'Unauthorized',
            ],
        ], 401);
    }
}
