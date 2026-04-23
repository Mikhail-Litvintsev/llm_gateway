<?php

namespace App\Components\ProviderGateway\Exceptions;

class ProviderRateLimitedException extends ProviderException
{
    public function __construct(
        string $providerName,
        ?int $httpStatus = 429,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct(
            errorCode: 'PROVIDER_RATE_LIMITED',
            message: "Provider '{$providerName}' returned rate limit (HTTP {$httpStatus}).",
            providerName: $providerName,
            httpStatus: $httpStatus,
        );
    }
}
