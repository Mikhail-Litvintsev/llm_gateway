<?php

declare(strict_types=1);

namespace App\Components\Sessions\DTO;

final readonly class SessionSendMessageInput
{
    public function __construct(
        public array $newUserContent,
        public ?int $maxTokens = null,
        public ?array $perRequestOverrides = null,
        public bool $stream = false,
    ) {}
}
