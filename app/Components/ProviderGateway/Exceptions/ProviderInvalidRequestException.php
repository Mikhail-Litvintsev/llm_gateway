<?php

namespace App\Components\ProviderGateway\Exceptions;

class ProviderInvalidRequestException extends ProviderException
{
    public function __construct(string $providerName, string $errorMessage = 'Unknown error')
    {
        parent::__construct(
            errorCode: 'PROVIDER_INVALID_REQUEST',
            message: "Provider '{$providerName}' rejected the request: {$errorMessage}",
            providerName: $providerName,
        );
    }
}
