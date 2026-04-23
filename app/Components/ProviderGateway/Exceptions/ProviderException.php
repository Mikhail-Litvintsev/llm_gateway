<?php

namespace App\Components\ProviderGateway\Exceptions;

class ProviderException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly ?string $providerName = null,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }
}
