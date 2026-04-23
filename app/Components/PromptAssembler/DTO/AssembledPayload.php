<?php

namespace App\Components\PromptAssembler\DTO;

readonly class AssembledPayload
{
    public function __construct(
        public array $body,
        public array $headers,
        public bool $structuredOutputFallback = false,
    ) {}

    public function toArray(): array
    {
        return ['body' => $this->body, 'headers' => array_keys($this->headers)];
    }
}
