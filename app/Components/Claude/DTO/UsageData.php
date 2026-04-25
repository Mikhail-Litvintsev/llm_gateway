<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

final readonly class UsageData
{
    /**
     * @param  array<int, array<string, mixed>>  $iterations
     */
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheCreation5mTokens,
        public int $cacheCreation1hTokens,
        public int $cacheReadTokens,
        public int $thinkingTokens,
        public int $serverToolWebSearchCount,
        public int $serverToolWebFetchCount,
        public int $serverToolCodeExecCount,
        public int $serverToolToolSearchCount,
        public array $iterations = [],
        public int $totalInputTokens = 0,
        public int $totalOutputTokens = 0,
        public int $totalCacheCreationTokens = 0,
        public int $totalCacheReadTokens = 0,
    ) {}
}
