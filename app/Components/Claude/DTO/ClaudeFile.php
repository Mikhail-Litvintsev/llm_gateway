<?php

declare(strict_types=1);

namespace App\Components\Claude\DTO;

use App\Models\FileRecord;

final readonly class ClaudeFile
{
    public function __construct(
        public string $fileId,
        public string $anthropicFileId,
        public string $filename,
        public string $mimeType,
        public int $sizeBytes,
        public string $uploadPurpose,
        public string $createdAt,
    ) {}

    public static function fromRecord(FileRecord $record): self
    {
        return new self(
            fileId: $record->file_id,
            anthropicFileId: $record->anthropic_file_id,
            filename: $record->filename,
            mimeType: $record->mime_type,
            sizeBytes: (int) $record->size_bytes,
            uploadPurpose: $record->upload_purpose,
            createdAt: $record->created_at->toIso8601String(),
        );
    }

    public function toArray(): array
    {
        return [
            'file_id' => $this->fileId,
            'anthropic_file_id' => $this->anthropicFileId,
            'filename' => $this->filename,
            'size_bytes' => $this->sizeBytes,
            'mime_type' => $this->mimeType,
            'upload_purpose' => $this->uploadPurpose,
            'created_at' => $this->createdAt,
        ];
    }
}
