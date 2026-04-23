<?php

namespace App\Components\RequestPipeline\DTO;

readonly class ToolParam
{
    /** @param ToolParam[] $nestedParams */
    public function __construct(
        public string $name,
        public string $type,
        public bool $required,
        public ?string $description,
        public ?string $enum,
        public ?string $default,
        public ?string $items,
        public ?string $properties,
        public array $nestedParams,
    ) {}
}
