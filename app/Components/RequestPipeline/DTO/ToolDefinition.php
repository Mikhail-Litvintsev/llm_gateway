<?php

namespace App\Components\RequestPipeline\DTO;

readonly class ToolDefinition
{
    /** @param ToolParam[] $params */
    public function __construct(
        public string $name,
        public string $description,
        public array $params,
    ) {}
}
