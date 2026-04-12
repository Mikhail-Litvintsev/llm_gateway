<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

final readonly class ResultLine
{
    public function __construct(
        public string $customId,
        public string $status,
        public ?array $result,
        public ?array $error,
    ) {}
}
