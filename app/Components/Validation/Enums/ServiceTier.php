<?php

declare(strict_types=1);

namespace App\Components\Validation\Enums;

enum ServiceTier: string
{
    case StandardOnly = 'standard_only';
    case Auto = 'auto';
}
