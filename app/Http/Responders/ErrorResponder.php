<?php

declare(strict_types=1);

namespace App\Http\Responders;

use Illuminate\Http\Response;

final readonly class ErrorResponder
{
    private const int JSON_OPTIONS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public function invalidRequest(string $message, string $gatewayRequestId): Response
    {
        return $this->build(400, 'invalid_request_error', $message, $gatewayRequestId);
    }

    public function notFound(string $message, string $gatewayRequestId): Response
    {
        return $this->build(404, 'not_found_error', $message, $gatewayRequestId);
    }

    public function billingCapExceeded(string $message, string $gatewayRequestId): Response
    {
        return $this->build(402, 'billing_error', $message, $gatewayRequestId);
    }

    public function authorizationError(string $errorType, string $message, int $httpStatus, string $gatewayRequestId): Response
    {
        return $this->build($httpStatus, $errorType, $message, $gatewayRequestId);
    }

    public function rateLimit(string $message, int $retryAfterSeconds, string $gatewayRequestId): Response
    {
        $response = $this->build(429, 'rate_limit_error', $message, $gatewayRequestId);
        $response->headers->set('Retry-After', (string) $retryAfterSeconds);

        return $response;
    }

    public function upstreamTimeout(string $message, string $gatewayRequestId): Response
    {
        return $this->build(504, 'upstream_timeout', $message, $gatewayRequestId);
    }

    public function upstreamError(string $message, int $httpStatus, string $gatewayRequestId): Response
    {
        return $this->build($httpStatus, 'api_error', $message, $gatewayRequestId);
    }

    private function build(int $httpStatus, string $errorType, string $message, string $gatewayRequestId): Response
    {
        $body = json_encode([
            'type' => 'error',
            'error' => ['type' => $errorType, 'message' => $message],
        ], self::JSON_OPTIONS);

        return new Response($body, $httpStatus, [
            'Content-Type' => 'application/json',
            'X-Gateway-Request-Id' => $gatewayRequestId,
        ]);
    }
}
