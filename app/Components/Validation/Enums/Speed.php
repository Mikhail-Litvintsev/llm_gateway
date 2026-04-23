<?php

declare(strict_types=1);

namespace App\Components\Validation\Enums;

enum Speed: string
{
    case Standard = 'standard';
    case Fast = 'fast';
}
