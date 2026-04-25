<?php

declare(strict_types=1);

namespace App\Components\Pricing\DTO;

final readonly class Money
{
    /** @var numeric-string */
    public string $amountUsd;

    public function __construct(string $amountUsd)
    {
        if (! is_numeric($amountUsd)) {
            throw new \InvalidArgumentException("Money amount must be numeric, got: $amountUsd");
        }

        $this->amountUsd = $amountUsd;
    }

    public function add(Money $other): Money
    {
        return new Money(bcadd($this->amountUsd, $other->amountUsd, 12));
    }

    public function multiply(string $factor): Money
    {
        if (! is_numeric($factor)) {
            throw new \InvalidArgumentException("Money factor must be numeric, got: $factor");
        }

        return new Money(bcmul($this->amountUsd, $factor, 12));
    }

    public static function zero(): Money
    {
        return new Money('0.000000000000');
    }

    public function toFloat(): float
    {
        return (float) $this->amountUsd;
    }
}
