<?php

namespace App\Components\RequestPipeline\DTO;

readonly class ParsedRequest
{
    /** @param PromptBlock[] $blocks */
    public function __construct(
        public string $version,
        public MetaData $meta,
        public ?ProviderConfig $provider,
        public array $blocks,
        public ?ToolsConfig $tools,
        public ?GenerationParameters $parameters,
        public CallbackConfig $callback,
        public string $rawPromptXml,
        public ?string $rawToolsXml,
        public ?string $rawParametersXml,
        public ?string $rawProviderXml = null,
    ) {}
}
