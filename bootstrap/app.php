<?php

use App\Components\Auth\ApiKeyAuth;
use App\Components\Claude\Exceptions\FileNotFoundException;
use App\Components\Claude\Exceptions\FileOwnershipMismatchException;
use App\Components\Claude\Payload\Exceptions\PayloadBuildException;
use App\Components\Sessions\Exceptions\SessionExpiredException;
use App\Components\Sessions\Exceptions\SessionNotFoundException;
use App\Components\Validation\Exceptions\FeatureNotAllowedException;
use App\Components\Validation\Exceptions\FeatureQuotaExhaustedException;
use App\Http\Middleware\InternalNetworkOnly;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('claude:poll-batches')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->onOneServer();

        $schedule->command('claude:flush-accumulator')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->onOneServer();

        $schedule->command('claude:cleanup-files')
            ->weeklyOn(0, '03:00')
            ->onOneServer()
            ->withoutOverlapping(30);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'auth.api_key' => ApiKeyAuth::class,
            'internal.network' => InternalNetworkOnly::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($e instanceof SessionNotFoundException) {
                return response()->json([
                    'type' => 'error',
                    'error' => ['type' => 'not_found_error', 'message' => 'Session not found'],
                ], 404);
            }

            if ($e instanceof SessionExpiredException) {
                return response()->json([
                    'type' => 'error',
                    'error' => ['type' => 'gone', 'message' => 'Session expired'],
                ], 410);
            }

            if ($e instanceof PayloadBuildException) {
                return response()->json([
                    'type' => 'error',
                    'error' => ['type' => 'invalid_request_error', 'message' => $e->getMessage()],
                ], 400);
            }

            if ($e instanceof FeatureNotAllowedException) {
                return response()->json([
                    'type' => 'error',
                    'error' => ['type' => 'permission_error', 'message' => $e->getMessage()],
                ], 403);
            }

            if ($e instanceof FeatureQuotaExhaustedException) {
                return response()->json([
                    'type' => 'error',
                    'error' => ['type' => 'quota_exhausted', 'message' => $e->getMessage()],
                ], 429);
            }

            if ($e instanceof FileOwnershipMismatchException) {
                return response()->json([
                    'type' => 'error',
                    'error' => ['type' => 'permission_error', 'message' => 'You do not have access to this file'],
                ], 403);
            }

            if ($e instanceof FileNotFoundException) {
                return response()->json([
                    'type' => 'error',
                    'error' => ['type' => 'not_found_error', 'message' => 'File not found'],
                ], 404);
            }

            if ($e instanceof RuntimeException && in_array($e->getCode(), [400, 403, 404, 409, 422, 429], true)) {
                return response()->json([
                    'type' => 'error',
                    'error' => ['type' => 'invalid_request_error', 'message' => $e->getMessage()],
                ], $e->getCode());
            }

            if ($request->is('api/*') || $request->is('v1/*')) {
                $status = match (true) {
                    $e instanceof ValidationException => 422,
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
