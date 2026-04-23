<?php

declare(strict_types=1);

namespace App\Components\Pricing\DTO;

final readonly class CodeExecutionConsumption
{
    public function __construct(
        public float $billedHours,
        public float $freeHoursRemainingAfter,
    ) {}
}
