<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

final readonly class UsageData
{
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
    ) {}
}
