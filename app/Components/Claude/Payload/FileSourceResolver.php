<?php

declare(strict_types=1);

namespace App\Components\Claude\Payload;

use App\Components\Claude\Exceptions\FileNotFoundException;
use App\Components\Claude\Exceptions\FileOwnershipMismatchException;
use App\Components\Claude\Files\FilesRepository;
use App\Components\Claude\Payload\Exceptions\PayloadBuildException;
use Illuminate\Support\Facades\Log;

final class FileSourceResolver
{
    private const int OUR_FILE_ID_LENGTH = 29;

    private const string OUR_FILE_ID_PATTERN = '/^file_[a-zA-Z0-9]{24}$/';

    public function __construct(
        private readonly FilesRepository $repository,
    ) {}

    /**
     * @param  array{type: string, file_id: string}  $source
     * @return array{type: string, file_id: string}
     */
    public function resolve(array $source, int $clientId, bool $allowRawAnthropicFileIds): array
    {
        $fileId = $source['file_id'];

        if (! $this->isOurFileId($fileId)) {
            return $this->handleExternalFileId($source, $allowRawAnthropicFileIds);
        }

        return $this->resolveOurFileId($source, $fileId, $clientId);
    }

    private function isOurFileId(string $fileId): bool
    {
        return strlen($fileId) === self::OUR_FILE_ID_LENGTH
            && preg_match(self::OUR_FILE_ID_PATTERN, $fileId) === 1;
    }

    /**
     * @param  array{type: string, file_id: string}  $source
     * @return array{type: string, file_id: string}
     */
    private function handleExternalFileId(array $source, bool $allowRawAnthropicFileIds): array
    {
        if (! $allowRawAnthropicFileIds) {
            throw PayloadBuildException::invalidRequest(
                'Unknown file ID format. Use gateway file IDs or enable allow_raw_anthropic_file_ids.'
            );
        }

        return $source;
    }

    /**
     * @param  array{type: string, file_id: string}  $source
     * @return array{type: string, file_id: string}
     */
    private function resolveOurFileId(array $source, string $fileId, int $clientId): array
    {
        $record = $this->repository->findByFileId($fileId);

        if ($record === null) {
            throw new FileNotFoundException($fileId);
        }

        if ($record->client_id !== $clientId) {
            Log::channel('llm')->warning('files.ownership_violation', [
                'requesting_client_id' => $clientId,
                'file_id' => $fileId,
                'owner_client_id' => $record->client_id,
            ]);

            throw new FileOwnershipMismatchException($fileId, $clientId, $record->client_id);
        }

        $source['file_id'] = $record->anthropic_file_id;

        return $source;
    }
}
