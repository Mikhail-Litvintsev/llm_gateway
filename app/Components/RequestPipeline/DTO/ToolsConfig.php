<?php

namespace App\Components\RequestPipeline\DTO;

readonly class ToolsConfig
{
    /** @param ToolDefinition[] $tools */
    public function __construct(
        public string $toolChoice,
        public array $tools,
    ) {}
}
