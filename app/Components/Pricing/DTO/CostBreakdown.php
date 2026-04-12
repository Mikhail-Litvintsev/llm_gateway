<?php

declare(strict_types=1);

namespace App\Components\Pricing\DTO;

final readonly class CostBreakdown
{
    public function __construct(
        public Money $inputCost,
        public Money $outputCost,
        public Money $cacheWrite5mCost,
        public Money $cacheWrite1hCost,
        public Money $cacheReadCost,
        public Money $serverToolWebSearchCost,
        public Money $serverToolCodeExecCost,
        public Money $geoMultiplierApplied,
        public Money $totalCost,
    ) {}
}
