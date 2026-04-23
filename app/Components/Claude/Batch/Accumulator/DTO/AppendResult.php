<?php

declare(strict_types=1);

namespace App\Components\Claude\Batch\Accumulator\DTO;

final readonly class AppendResult
{
    public function __construct(
        public string $bucketId,
        public int $position,
        public string $customId,
    ) {}

    public function toArray(): array
    {
        return [
            'accepted' => true,
            'bucket_id' => $this->bucketId,
            'position' => $this->position,
            'custom_id' => $this->customId,
        ];
    }
}
