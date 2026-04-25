<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

final readonly class BatchItemInput
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public string $customId,
        public array $params,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            customId: $data['custom_id'],
            params: $data['params'],
        );
    }
}
