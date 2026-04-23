<?php

namespace App\Components\ProviderGateway\Exceptions;

class ProviderInsufficientFundsException extends ProviderException
{
    public function __construct(string $providerName, ?int $httpStatus = 402)
    {
        parent::__construct(
            errorCode: 'PROVIDER_INSUFFICIENT_FUNDS',
            message: "Provider '{$providerName}' returned insufficient funds (HTTP {$httpStatus}).",
            providerName: $providerName,
            httpStatus: $httpStatus,
        );
    }
}
