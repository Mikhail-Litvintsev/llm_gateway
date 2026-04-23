<?php

declare(strict_types=1);

namespace App\Components\Delivery\Stream\DTO;

final readonly class StreamOutcome
{
    public function __construct(
        public StreamAggregate $aggregate,
        public float $costUsd,
        public array $costBreakdown,
        public int $latencyMs,
        public bool $clientDisconnected,
        public bool $completed,
        public ?string $errorType = null,
        public ?array $anthropicHeaders = null,
        public ?int $httpStatusCode = null,
        public ?string $firstChunkBody = null,
    ) {}
}
