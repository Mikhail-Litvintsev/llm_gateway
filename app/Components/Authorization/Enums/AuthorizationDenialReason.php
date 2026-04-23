<?php

declare(strict_types=1);

namespace App\Components\Authorization\Enums;

enum AuthorizationDenialReason: string
{
    case ModelNotAllowed = 'model_not_allowed';
    case FeatureNotAllowed = 'feature_not_allowed';
    case MonthlySpendCapExceeded = 'monthly_spend_cap_exceeded';

    public function httpStatusCode(): int
    {
        return match ($this) {
            self::ModelNotAllowed, self::FeatureNotAllowed => 403,
            self::MonthlySpendCapExceeded => 402,
        };
    }

    public function errorType(): string
    {
        return match ($this) {
            self::ModelNotAllowed, self::FeatureNotAllowed => 'permission_error',
            self::MonthlySpendCapExceeded => 'billing_error',
        };
    }
}
