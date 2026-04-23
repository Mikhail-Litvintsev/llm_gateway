<?php

namespace App\Components\RequestPipeline\DTO;

readonly class ResponseFormatConfig
{
    public function __construct(
        public string $type,
        public ?string $name,
        public ?bool $strict,
        public ?string $schema,
    ) {}
}
