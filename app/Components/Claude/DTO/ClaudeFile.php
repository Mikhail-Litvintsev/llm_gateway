<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

final readonly class ClaudeFile
{
    public function __construct(
        public string $anthropicFileId,
        public string $filename,
        public string $mimeType,
        public int    $sizeBytes,
        public string $purpose,
    ) {}
}
