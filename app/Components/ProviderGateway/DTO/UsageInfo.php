<?php

namespace App\Components\ProviderGateway\DTO;

readonly class UsageInfo
{
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheCreationTokens = 0,
        public int $cacheReadTokens = 0,
    ) {}
}
