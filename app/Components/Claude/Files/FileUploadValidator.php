<?php

declare(strict_types=1);

namespace App\Components\Claude\Files;

use Illuminate\Http\UploadedFile;

final class FileUploadValidator
{
    private const array ALLOWED_MIME_BY_PURPOSE = [
        'vision' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
        ],
        'document' => [
            'application/pdf',
            'text/plain',
            'text/markdown',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ],
        'code_execution_input' => [
            'text/plain',
            'text/csv',
            'application/json',
            'application/zip',
        ],
        'other' => ['*'],
    ];

    /**
     * @return array{code: string, status: int}|null
     */
    public function validate(UploadedFile $file, FilePurpose $purpose): ?array
    {
        $sizeBytes = $file->getSize();

        if ($sizeBytes === 0 || $sizeBytes === false) {
            return ['code' => 'empty_file', 'status' => 400];
        }

        $maxBytes = (int) config('llm.max_file_size_mb', 500) * 1024 * 1024;

        if ($sizeBytes > $maxBytes) {
            return ['code' => 'file_too_large', 'status' => 413];
        }

        $detectedMime = (string) $file->getMimeType();
        $allowedMimes = self::ALLOWED_MIME_BY_PURPOSE[$purpose->value];

        if ($allowedMimes !== ['*'] && !in_array($detectedMime, $allowedMimes, true)) {
            return ['code' => 'mime_not_allowed_for_purpose', 'status' => 400];
        }

        return null;
    }
}
