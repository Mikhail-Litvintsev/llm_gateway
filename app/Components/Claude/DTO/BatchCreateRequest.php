<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

final readonly class BatchCreateRequest
{
    public function __construct(
        public array $items,
    ) {}
}
