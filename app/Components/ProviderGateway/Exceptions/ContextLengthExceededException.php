<?php

namespace App\Components\ProviderGateway\Exceptions;

class ContextLengthExceededException extends ProviderException
{
    public function __construct(string $providerName)
    {
        parent::__construct(
            errorCode: 'PROVIDER_CONTEXT_LENGTH',
            message: "Context length exceeded for provider '{$providerName}'.",
            providerName: $providerName,
        );
    }
}
