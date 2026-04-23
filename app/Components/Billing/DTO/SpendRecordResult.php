<?php

declare(strict_types=1);

namespace App\Components\Billing\DTO;

final readonly class SpendRecordResult
{
    public function __construct(
        public float $newTotalUsd,
        public ?float $remainingUsd,
        public bool $capJustExceeded,
    ) {}
}
