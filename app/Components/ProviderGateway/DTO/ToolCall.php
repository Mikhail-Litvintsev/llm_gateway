<?php

namespace App\Components\ProviderGateway\DTO;

readonly class ToolCall
{
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
    ) {}

    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'arguments' => $this->arguments];
    }
}
