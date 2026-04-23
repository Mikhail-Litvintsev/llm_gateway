<?php

declare(strict_types=1);

namespace App\Components\Claude\Enums;

enum ThinkingMode: string
{
    case Off = 'off';
    case Adaptive = 'adaptive';
    case Manual = 'enabled';
}
