<?php

namespace App\Components\RequestPipeline\DTO;

readonly class ProviderConfig
{
    public function __construct(
        public ?string $name,
        public ?string $model,
        public ?ProviderConfig $fallback,
    ) {}
}
