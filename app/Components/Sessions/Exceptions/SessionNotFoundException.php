<?php

declare(strict_types=1);

namespace App\Components\Sessions\Exceptions;

use RuntimeException;

final class SessionNotFoundException extends RuntimeException
{
    public function __construct(string $publicId)
    {
        parent::__construct("Session not found: $publicId", 404);
    }
}
