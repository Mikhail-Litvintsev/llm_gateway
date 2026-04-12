<?php

declare(strict_types=1);

namespace App\Components\RateLimiting\Claude\DTO;

use DateTimeImmutable;

final readonly class RateLimitSnapshot
{
    public function __construct(
        public int $requestsLimit,
        public int $requestsRemaining,
        public DateTimeImmutable $requestsResetAt,
        public int $tokensLimit,
        public int $tokensRemaining,
        public DateTimeImmutable $tokensResetAt,
        public int $inputTokensLimit,
        public int $inputTokensRemaining,
        public DateTimeImmutable $inputTokensResetAt,
        public int $outputTokensLimit,
        public int $outputTokensRemaining,
        public DateTimeImmutable $outputTokensResetAt,
        public DateTimeImmutable $recordedAt,
    ) {}
}
