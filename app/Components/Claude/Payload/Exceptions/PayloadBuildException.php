<?php

declare(strict_types=1);

namespace App\Components\Claude\Payload\Exceptions;

use RuntimeException;
use Throwable;

final class PayloadBuildException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $type = 'invalid_request_error',
        public readonly int $statusCode = 400,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public static function invalidRequest(string $message): self
    {
        return new self($message, 'invalid_request_error', 400);
    }

    public static function permissionError(string $message): self
    {
        return new self($message, 'permission_error', 403);
    }

    public static function requestTooLarge(string $message): self
    {
        return new self($message, 'request_too_large', 400);
    }
}
