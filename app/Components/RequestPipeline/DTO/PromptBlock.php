<?php

namespace App\Components\RequestPipeline\DTO;

readonly class PromptBlock
{
    public function __construct(
        public string $type,
        public string $role,
        public ?string $id,
        public ?string $label,
        public ?string $format,
        public ?string $mediaType,
        public ?string $for,
        public ?string $toolCallId,
        public bool $cache,
        public string $content,
    ) {}
}
