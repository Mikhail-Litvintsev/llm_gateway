<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

final readonly class MessageRequest
{
    public function __construct(
        public string $modelAlias,
        public array $messages,
        public int $maxTokens,
        public ?string $system,
        public ?array $tools,
        public ?array $toolChoice,
        public ?array $thinking,
        public ?array $cacheControl,
        public bool $stream,
        public ?string $serviceTier,
        public ?array $metadata,
    ) {}
}
