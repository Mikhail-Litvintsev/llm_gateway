<?php

namespace App\Http\Middleware;

use App\Components\Auth\ApiAuthenticator;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $authorization = $request->header('Authorization');

        if (!$authorization || !str_starts_with($authorization, 'Bearer ')) {
            return response()->json([
                'status' => 'error',
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Missing or invalid Authorization header.',
                ],
            ], 401);
        }

        $token = substr($authorization, 7);

        try {
            $client = app(ApiAuthenticator::class)->authenticate($token);
        } catch (AuthorizationException) {
            return response()->json([
                'status' => 'error',
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Invalid or revoked API key.',
                ],
            ], 403);
        }

        $request->attributes->set('api_client', $client);

        return $next($request);
    }
}
