<?php

declare(strict_types=1);

namespace App\Components\Billing\Enums;

enum SpendGateDecision: string
{
    case AllowedUnlimited = 'allowed_unlimited';
    case AllowedWithinCap = 'allowed_within_cap';
    case SoftCapExceeded = 'soft_cap_exceeded';
    case HardCapExceeded = 'hard_cap_exceeded';

    public function isAllowed(): bool
    {
        return match ($this) {
            self::AllowedUnlimited, self::AllowedWithinCap => true,
            self::SoftCapExceeded, self::HardCapExceeded => false,
        };
    }
}
