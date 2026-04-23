<?php

namespace App\Components\ProviderGateway\DTO;

readonly class ProviderResponse
{
    /** @param ToolCall[] $toolCalls */
    public function __construct(
        public ?string $content,
        public array $toolCalls,
        public string $finishReason,
        public UsageInfo $usage,
        public ?array $reasoning,
        public bool $structuredOutputFallback,
    ) {}
}
