<?php

namespace App\Components\RequestPipeline\DTO;

readonly class CallbackConfig
{
    public function __construct(
        public string $url,
        public string $method,
        public array $headers,
        public int $timeout,
        public RetryConfig $retry,
    ) {}
}
