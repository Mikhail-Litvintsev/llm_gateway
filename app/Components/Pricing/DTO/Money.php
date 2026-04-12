<?php

declare(strict_types=1);

namespace App\Components\Pricing\DTO;

final readonly class Money
{
    public function __construct(
        public string $amountUsd,
    ) {}

    public function add(Money $other): Money
    {
        return new Money(bcadd($this->amountUsd, $other->amountUsd, 12));
    }

    public function multiply(string $factor): Money
    {
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
