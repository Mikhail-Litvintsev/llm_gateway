<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

final readonly class TokenCountResult
{
    public function __construct(
        public int $inputTokens,
    ) {}
}
