<?php

declare(strict_types=1);

namespace App\Components\Routing\DTO;

final readonly class ResolvedModel
{
    public function __construct(
        public string $alias,
        public string $snapshot,
        public array $capabilities,
        public array $pricing,
    ) {}
}
