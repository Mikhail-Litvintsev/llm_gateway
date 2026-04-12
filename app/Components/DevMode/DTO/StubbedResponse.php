<?php

declare(strict_types=1);

namespace App\Components\DevMode\DTO;

use App\Components\Claude\DTO\UsageData;

final readonly class StubbedResponse
{
    public function __construct(
        public array     $anthropicBody,
        public array     $anthropicHeaders,
        public UsageData $usage,
    ) {}
}
