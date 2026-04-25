<?php

declare(strict_types=1);

namespace App\Components\Sessions\DTO;

final readonly class SessionSendMessageInput
{
    /**
     * @param  array<int, array<string, mixed>>  $newUserContent
     * @param  array<string, mixed>|null  $perRequestOverrides
     */
    public function __construct(
        public array $newUserContent,
        public ?int $maxTokens = null,
        public ?array $perRequestOverrides = null,
        public bool $stream = false,
    ) {}
}
