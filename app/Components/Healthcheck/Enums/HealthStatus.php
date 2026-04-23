<?php

declare(strict_types=1);

namespace App\Components\Healthcheck\Enums;

enum HealthStatus: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
    case Down = 'down';
}
