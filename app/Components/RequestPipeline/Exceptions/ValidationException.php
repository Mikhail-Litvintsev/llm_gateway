<?php

namespace App\Components\RequestPipeline\Exceptions;

class ValidationException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
