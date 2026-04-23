<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

use App\Components\Claude\Enums\BatchStatus;
use App\Models\BatchRecord;

final readonly class Batch
{
    public function __construct(
        public string $batchId,
        public BatchStatus $status,
        public int $requestCount,
        public ?string $anthropicBatchId,
        public string $createdAt,
        public int $succeededCount = 0,
        public int $erroredCount = 0,
        public int $cancelledCount = 0,
        public int $expiredCount = 0,
    ) {}

    public static function fromRecord(BatchRecord $record): self
    {
        return new self(
            batchId: $record->batch_id,
            status: $record->status,
            requestCount: $record->request_count,
            anthropicBatchId: $record->anthropic_batch_id,
            createdAt: $record->created_at->toIso8601String(),
            succeededCount: (int) $record->succeeded_count,
            erroredCount: (int) $record->errored_count,
            cancelledCount: (int) $record->cancelled_count,
            expiredCount: (int) $record->expired_count,
        );
    }

    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'status' => $this->status->value,
            'request_count' => $this->requestCount,
            'anthropic_batch_id' => $this->anthropicBatchId,
            'created_at' => $this->createdAt,
            'succeeded_count' => $this->succeededCount,
            'errored_count' => $this->erroredCount,
            'cancelled_count' => $this->cancelledCount,
            'expired_count' => $this->expiredCount,
        ];
    }
}
