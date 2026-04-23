<?php

declare(strict_types=1);

namespace App\Components\Sessions\DTO;

use DateTimeImmutable;

final readonly class SessionMetadata
{
    public function __construct(
        public string $publicId,
        public string $modelAlias,
        public int $messageCount,
        public ?DateTimeImmutable $lastCompactionAt,
        public ?DateTimeImmutable $expiresAt,
        public string $status,
        public int $compactionCount,
        public float $totalCostUsd,
    ) {}
}
