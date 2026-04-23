<?php

namespace App\Http\Middleware;

use App\Components\RateLimiter\RequestThrottle;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RateLimitApiKey
{
    public function __construct(
        private readonly RequestThrottle $throttle,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->attributes->get('api_client');
        if (!$client) {
            return $next($request);
        }

        $result = $this->throttle->attempt($client->id, $client->rateLimit);

        if (!$result->allowed) {
            return response()->json([
                'status' => 'error',
                'error' => [
                    'code' => 'RATE_LIMIT_EXCEEDED',
                    'message' => "Rate limit exceeded. Retry after {$result->retryAfter} seconds.",
                ],
            ], 429)
            ->withHeaders([
                'Retry-After' => $result->retryAfter,
                'X-RateLimit-Limit' => $result->limit,
                'X-RateLimit-Remaining' => 0,
                'X-RateLimit-Reset' => $result->resetTimestamp,
            ]);
        }

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string) $result->limit);
        $response->headers->set('X-RateLimit-Remaining', (string) $result->remaining);
        $response->headers->set('X-RateLimit-Reset', (string) $result->resetTimestamp);

        return $response;
    }
}
