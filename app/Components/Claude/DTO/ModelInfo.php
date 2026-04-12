<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

final readonly class ModelInfo
{
    public function __construct(
        public string $id,
        public string $displayName,
        public int    $contextWindow,
        public int    $maxOutput,
    ) {}
}
