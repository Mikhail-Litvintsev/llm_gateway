<?php

namespace App\Components\ProviderGateway\Exceptions;

class ProviderTimeoutException extends ProviderException
{
    public function __construct(string $providerName)
    {
        parent::__construct(
            errorCode: 'PROVIDER_TIMEOUT',
            message: "Provider '{$providerName}' did not respond in time.",
            providerName: $providerName,
        );
    }
}
