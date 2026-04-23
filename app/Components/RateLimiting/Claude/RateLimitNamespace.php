<?php

declare(strict_types=1);

namespace App\Components\RateLimiting\Claude;

enum RateLimitNamespace: string
{
    case Messages = 'messages';
    case Priority = 'priority';
    case Fast = 'fast';
    case BatchCreate = 'batch_create';
}
