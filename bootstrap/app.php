<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        $middleware->alias([
            'auth.api_key'     => \App\Components\Auth\ApiKeyAuth::class,
            'internal.network' => \App\Http\Middleware\InternalNetworkOnly::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->is('v1/*')) {
                $status = match (true) {
                    $e instanceof \Illuminate\Auth\AuthenticationException => 401,
                    $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException => $e->getStatusCode(),
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
