<?php

declare(strict_types=1);

namespace App\Components\Delivery\Stream\DTO;

final readonly class StreamAggregate
{
    public function __construct(
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $cacheCreationInputTokens = null,
        public ?int $cacheReadInputTokens = null,
        public ?int $thinkingTokens = null,
        public ?string $stopReason = null,
        public ?string $serviceTier = null,
        public ?string $anthropicError = null,
        public int $eventsSeen = 0,
        public bool $completed = false,
        public bool $errored = false,
        public int $malformedEventCount = 0,
    ) {}
}
