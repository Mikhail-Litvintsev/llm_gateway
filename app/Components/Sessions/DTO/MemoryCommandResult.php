<?php

declare(strict_types=1);

namespace App\Components\Sessions\DTO;

final readonly class MemoryCommandResult
{
    public function __construct(
        public string $toolUseId,
        public string $text,
        public bool $isError,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toToolResultBlock(): array
    {
        return [
            'type' => 'tool_result',
            'tool_use_id' => $this->toolUseId,
            'content' => [['type' => 'text', 'text' => $this->text]],
            'is_error' => $this->isError,
        ];
    }
}
