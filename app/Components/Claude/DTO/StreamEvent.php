<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

final readonly class StreamEvent
{
    public function __construct(
        public string $type,
        public array  $data,
    ) {}
}
