<?php

namespace App\Components\RequestPipeline\DTO;

readonly class RetryConfig
{
    public function __construct(
        public int $maxAttempts,
        public string $backoff,
        public int $initialDelay,
    ) {}
}
