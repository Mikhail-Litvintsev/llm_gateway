<?php

declare(strict_types=1);

namespace App\Components\Sessions\Exceptions;

use RuntimeException;

final class SessionExpiredException extends RuntimeException
{
    public function __construct(string $publicId)
    {
        parent::__construct("Session expired: $publicId", 410);
    }
}
