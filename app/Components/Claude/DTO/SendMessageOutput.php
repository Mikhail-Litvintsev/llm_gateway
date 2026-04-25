<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

use App\Components\Delivery\Sync\DTO\AnthropicResponseEnvelope;

final readonly class SendMessageOutput
{
    /**
     * @param  array<string, mixed>|null  $parsedResponse
     * @param  array<string, mixed>|null  $usage
     * @param  array<string, mixed>  $costBreakdown
     */
    public function __construct(
        public AnthropicResponseEnvelope $envelope,
        public ?array $parsedResponse,
        public ?array $usage,
        public float $costUsd,
        public array $costBreakdown,
        public ?string $serviceTierUsed,
        public ?int $cacheHitTokens,
        public ?string $anthropicRequestId,
        public int $latencyMs,
        public bool $isSuccess,
        public ?string $errorType = null,
        public ?string $errorMessage = null,
    ) {}
}
