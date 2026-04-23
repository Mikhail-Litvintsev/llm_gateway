<?php

namespace App\Components\ProviderGateway\DTO;

readonly class RawProviderResponse
{
    public function __construct(
        public int $httpStatus,
        public array $body,
        public array $headers,
        public int $durationMs,
    ) {}

    public function isSuccess(): bool
    {
        return $this->httpStatus >= 200 && $this->httpStatus < 300;
    }

    public function isRateLimited(): bool
    {
        return $this->httpStatus === 429;
    }

    public function isInsufficientFunds(): bool
    {
        return $this->httpStatus === 402;
    }

    public function isServerError(): bool
    {
        return $this->httpStatus >= 500;
    }
}
