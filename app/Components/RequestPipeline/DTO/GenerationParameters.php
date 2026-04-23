<?php

namespace App\Components\RequestPipeline\DTO;

readonly class GenerationParameters
{
    public function __construct(
        public ?float $temperature,
        public ?int $maxTokens,
        public ?float $topP,
        public ?int $topK,
        public ?array $stopSequences,
        public ?ResponseFormatConfig $responseFormat,
        public bool $stream,
        public ?ReasoningConfig $reasoning,
        public array $extra,
    ) {}
}
