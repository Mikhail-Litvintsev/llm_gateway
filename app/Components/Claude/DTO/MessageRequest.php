<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

final readonly class MessageRequest
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>|null  $tools
     * @param  array<string, mixed>|null  $toolChoice
     * @param  array<string, mixed>|null  $thinking
     * @param  array<string, mixed>|null  $cacheControl
     * @param  array<string, mixed>|null  $metadata
     */
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
