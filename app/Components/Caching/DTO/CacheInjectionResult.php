<?php

declare(strict_types=1);

namespace App\Components\Caching\DTO;

use App\Components\Caching\Enums\CacheInjectionOutcome;

readonly class CacheInjectionResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
        public CacheInjectionOutcome $outcome,
        public ?int $estimatedPrefixTokens = null,
        public ?string $reason = null,
    ) {}
}
