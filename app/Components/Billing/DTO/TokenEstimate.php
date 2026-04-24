<?php

declare(strict_types=1);

namespace App\Components\Billing\DTO;

final readonly class TokenEstimate
{
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheReadTokens = 0,
    ) {}
}
