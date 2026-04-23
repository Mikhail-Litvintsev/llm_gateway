<?php

namespace App\Components\RequestPipeline\DTO;

readonly class ReasoningConfig
{
    public function __construct(
        public bool $enabled,
        public ?string $effort,
        public ?int $maxTokens,
    ) {}
}
