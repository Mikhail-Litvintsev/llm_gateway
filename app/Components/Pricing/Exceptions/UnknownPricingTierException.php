<?php

declare(strict_types=1);

namespace App\Components\Pricing\Exceptions;

final class UnknownPricingTierException extends \DomainException
{
    public function __construct(string $alias)
    {
        parent::__construct("Unknown pricing tier: {$alias}");
    }
}
