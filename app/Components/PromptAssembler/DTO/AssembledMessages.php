<?php

namespace App\Components\PromptAssembler\DTO;

readonly class AssembledMessages
{
    public function __construct(
        public string $systemPrompt,
        public array $messages,
    ) {}

    public function withSystemPrompt(string $systemPrompt): self
    {
        return new self(
            systemPrompt: $systemPrompt,
            messages: $this->messages,
        );
    }
}
