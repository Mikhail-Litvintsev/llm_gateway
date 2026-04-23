<?php

declare(strict_types=1);

namespace App\Components\Logging\Exceptions;

use RuntimeException;

final class IdempotencyException extends RuntimeException
{
    public function __construct(string $requestId)
    {
        parent::__construct("Duplicate request_id: $requestId");
    }
}
