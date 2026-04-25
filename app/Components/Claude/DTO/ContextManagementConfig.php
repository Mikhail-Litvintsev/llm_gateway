<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

final readonly class ContextManagementConfig
{
    /**
     * @param  array<string, mixed>|null  $compaction
     * @param  array<string, mixed>|null  $clearToolUses
     * @param  array<string, mixed>|null  $clearThinking
     */
    public function __construct(
        public ?array $compaction = null,
        public ?array $clearToolUses = null,
        public ?array $clearThinking = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $raw
     */
    public static function fromArray(?array $raw): self
    {
        if ($raw === null || $raw === []) {
            return new self;
        }

        return new self(
            compaction: $raw['compaction'] ?? null,
            clearToolUses: $raw['clear_tool_uses'] ?? null,
            clearThinking: $raw['clear_thinking'] ?? null,
        );
    }

    public function isEmpty(): bool
    {
        return $this->compaction === null
            && $this->clearToolUses === null
            && $this->clearThinking === null;
    }
}
