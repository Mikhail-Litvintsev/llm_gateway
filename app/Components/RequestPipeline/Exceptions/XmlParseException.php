<?php

namespace App\Components\RequestPipeline\Exceptions;

class XmlParseException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
