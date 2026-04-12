<?php

declare(strict_types=1);

namespace App\Components\Pricing;

use App\Components\Claude\DTO\UsageData;
use App\Components\Pricing\DTO\CostBreakdown;

final class Pricing
{
    public function __construct(
        private readonly CostCalculator $calculator,
    ) {}

    public function calculate(UsageData $usage, string $modelAlias, bool $isBatched, bool $geoUs): CostBreakdown
    {
        return $this->calculator->calculate($usage, $modelAlias, $isBatched, $geoUs);
    }
}
