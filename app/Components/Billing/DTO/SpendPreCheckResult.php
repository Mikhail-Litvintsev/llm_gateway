<?php

declare(strict_types=1);

namespace App\Components\Billing\DTO;

use App\Components\Billing\Enums\SpendGateDecision;

final readonly class SpendPreCheckResult
{
    public function __construct(
        public SpendGateDecision $decision,
        public float $currentSpendUsd,
        public ?float $capUsd,
    ) {}

    public function isAllowed(): bool
    {
        return $this->decision->isAllowed();
    }
}
