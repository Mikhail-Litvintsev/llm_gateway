<?php

declare(strict_types=1);

namespace App\Components\RateLimiting\Claude\Exceptions;

final class RateLimitExceededException extends \RuntimeException
{
    public function __construct(
        public readonly string $axis,
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct("Rate limit exceeded on axis: {$axis}, retry after {$retryAfterSeconds}s");
    }
}
