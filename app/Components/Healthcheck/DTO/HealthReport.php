<?php

declare(strict_types=1);

namespace App\Components\Healthcheck\DTO;

use App\Components\Healthcheck\Enums\HealthStatus;
use Carbon\Carbon;

final readonly class HealthReport
{
    public function __construct(
        public HealthStatus $overall,
        public array $components,
        public ?Carbon $anthropicLastCheckAt,
        public ?HealthStatus $anthropicLastStatus,
    ) {}
}
