<?php

declare(strict_types=1);

namespace App\Components\Claude\Files\DTO;

use App\Components\Claude\DTO\ClaudeFile;

final readonly class FileListPage
{
    /**
     * @param  ClaudeFile[]  $files
     */
    public function __construct(
        public array $files,
        public ?string $nextCursor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'files' => array_map(fn (ClaudeFile $f) => $f->toArray(), $this->files),
            'next_cursor' => $this->nextCursor,
        ];
    }
}
