<?php

use App\Components\Auth\ApiKeyAuth;
use App\Http\Middleware\InternalNetworkOnly;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'auth.api_key' => ApiKeyAuth::class,
            'internal.network' => InternalNetworkOnly::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->is('v1/*')) {
                $status = match (true) {
                    $e instanceof AuthenticationException => 401,
                    $e instanceof HttpException => $e->getStatusCode(),
                    default => 500,
                };

                return response()->json([
                    'status' => 'error',
                    'error' => [
                        'code' => $status === 500 ? 'INTERNAL_ERROR' : 'REQUEST_ERROR',
                        'message' => $status === 500
                            ? 'An internal error occurred.'
                            : $e->getMessage(),
                    ],
                ], $status);
            }
        });
    })->create();
