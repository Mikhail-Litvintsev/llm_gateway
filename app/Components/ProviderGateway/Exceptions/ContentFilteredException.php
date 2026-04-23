<?php

namespace App\Components\ProviderGateway\Exceptions;

class ContentFilteredException extends ProviderException
{
    public function __construct(string $providerName)
    {
        parent::__construct(
            errorCode: 'PROVIDER_CONTENT_FILTERED',
            message: "Content was filtered by provider '{$providerName}' moderation.",
            providerName: $providerName,
        );
    }
}
