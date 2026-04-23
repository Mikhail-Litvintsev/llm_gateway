<?php

declare(strict_types=1);

namespace App\Components\Delivery\Sync\DTO;

final readonly class GatewayHeaders
{
    /**
     * @param array<string, float> $costBreakdown
     */
    public function __construct(
        public string $gatewayRequestId,
        public ?string $anthropicRequestId,
        public string $modelAlias,
        public string $modelSnapshot,
        public float $costUsd,
        public array $costBreakdown,
        public ?float $spendRemainingUsd,
        public ?string $serviceTierUsed = null,
        public ?int $cacheHitTokens = null,
    ) {}
}
