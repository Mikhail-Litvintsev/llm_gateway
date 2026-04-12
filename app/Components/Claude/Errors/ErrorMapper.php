<?php

declare(strict_types=1);

namespace App\Components\Claude\Errors;

final class ErrorMapper
{
    public function mapHttpStatus(int $status): string
    {
        return match ($status) {
            400 => 'invalid_request',
            401 => 'authentication_error',
            402 => 'billing_error',
            403 => 'permission_error',
            404 => 'not_found',
            409 => 'conflict',
            413 => 'payload_too_large',
            429 => 'rate_limit',
            500 => 'upstream_error',
            504 => 'upstream_timeout',
            529 => 'overloaded',
            default => 'unknown',
        };
    }

    public function mapStreamErrorEvent(array $event): string
    {
        $type = $event['error']['type'] ?? 'unknown';

        return match ($type) {
            'overloaded_error'      => 'overloaded',
            'api_error'             => 'upstream_error',
            'invalid_request_error' => 'invalid_request',
            'authentication_error'  => 'authentication_error',
            'permission_error'      => 'permission_error',
            'rate_limit_error'      => 'rate_limit',
            default                 => 'unknown',
        };
    }
}
