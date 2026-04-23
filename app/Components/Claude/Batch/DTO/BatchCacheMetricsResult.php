<?php

declare(strict_types=1);

namespace App\Components\Claude\Batch\DTO;

final readonly class BatchCacheMetricsResult
{
    public function __construct(
        public int $totalCacheReadTokens,
        public int $totalInputTokens,
        public int $totalOutputTokens,
        public ?string $cacheHitRatio,
        public ?string $totalSavingsFromCachingUsd,
    ) {}
}
