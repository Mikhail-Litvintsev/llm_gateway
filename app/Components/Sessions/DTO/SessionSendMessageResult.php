<?php

declare(strict_types=1);

namespace App\Components\Sessions\DTO;

final readonly class SessionSendMessageResult
{
    /**
     * @param  array<int, array<string, mixed>>  $assistantContent
     * @param  array<string, mixed>  $usage
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $publicId,
        public int $messageCount,
        public float $totalCostUsd,
        public string $stopReason,
        public array $assistantContent,
        public array $usage,
        public ?string $model,
        public array $warnings,
    ) {}
}
