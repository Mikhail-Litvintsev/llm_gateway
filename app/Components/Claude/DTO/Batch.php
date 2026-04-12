<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

final readonly class Batch
{
    public function __construct(
        public string              $anthropicBatchId,
        public string              $status,
        public int                 $requestCount,
        public int                 $succeededCount,
        public int                 $erroredCount,
        public ?string             $resultsUrl,
        public ?\DateTimeImmutable $completedAt,
    ) {}
}
