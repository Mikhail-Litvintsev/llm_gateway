<?php

declare(strict_types=1);

namespace App\Components\Logging\DTO;

final readonly class LoggingResult
{
    public function __construct(
        public string $requestId,
    ) {}
}
