<?php

namespace App\Components\RateLimiter\DTO;

readonly class ThrottleResult
{
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public int $resetTimestamp,
        public ?int $retryAfter,
    ) {}
}
