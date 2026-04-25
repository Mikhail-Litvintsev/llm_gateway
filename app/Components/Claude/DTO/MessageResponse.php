<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

final readonly class MessageResponse
{
    /**
     * @param  array<int, array<string, mixed>>  $content
     * @param  array<string, mixed>  $usage
     * @param  array<string, string>  $rateLimitHeaders
     * @param  array<string, int>  $serverToolUseCounts
     * @param  array<int, array<string, mixed>>  $iterations
     * @param  array<int, array<string, mixed>>  $memoryToolUses
     * @param  array<int, array<string, mixed>>  $citations
     * @param  list<array<string, string>>  $warnings
     */
    public function __construct(
        public string $anthropicId,
        public string $role,
        public array $content,
        public string $model,
        public ?string $stopReason,
        public array $usage,
        public ?string $anthropicRequestId,
        public ?string $anthropicOrganizationId,
        public array $rateLimitHeaders,
        public ?string $serviceTierUsed = null,
        public bool $compactionDetected = false,
        public array $serverToolUseCounts = [],
        public int $thinkingTokens = 0,
        public array $iterations = [],
        public array $memoryToolUses = [],
        public array $citations = [],
        public array $warnings = [],
    ) {}
}
