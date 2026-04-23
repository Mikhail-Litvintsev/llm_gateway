<?php

declare(strict_types=1);

namespace App\Components\Billing\Exceptions;

use RuntimeException;

final class HardCapExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $clientId,
        public readonly float $currentSpendUsd,
        public readonly float $capUsd,
    ) {
        parent::__construct(
            "Hard cap exceeded for client $clientId: spend $currentSpendUsd >= cap $capUsd"
        );
    }
}
