<?php

namespace App\Components\ProviderGateway\Exceptions;

class ProviderUnavailableException extends ProviderException
{
    public function __construct(string $providerName, ?int $httpStatus = null)
    {
        parent::__construct(
            errorCode: 'PROVIDER_UNAVAILABLE',
            message: "Provider '{$providerName}' is unavailable (HTTP {$httpStatus}).",
            providerName: $providerName,
            httpStatus: $httpStatus,
        );
    }
}
