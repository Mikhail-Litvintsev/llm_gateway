<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

final readonly class BatchItemInput
{
    public function __construct(
        public string $customId,
        public array $params,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            customId: $data['custom_id'],
            params: $data['params'],
        );
    }
}
