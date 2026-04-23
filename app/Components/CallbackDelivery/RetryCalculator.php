<?php

namespace App\Components\CallbackDelivery;

class RetryCalculator
{
    public function calculateDelay(int $attempt, string $backoff, int $initialDelay): int
    {
        return match ($backoff) {
            'exponential' => $initialDelay * (2 ** ($attempt - 1)),
            'fixed' => $initialDelay,
            default => $initialDelay,
        };
    }
}
