<?php

declare(strict_types=1);

namespace App\Components\Claude\Files;

use App\Components\Claude\DTO\ClaudeFile;
use App\Components\Routing\WorkspaceResolver;
use App\Models\Client;
use App\Models\FileRecord;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class FilesUploadHandler
{
    public function __construct(
        private FileUploadValidator $validator,
        private WorkspaceResolver $workspaces,
    ) {}

    /**
     * @throws ConnectionException
     */
    public function upload(Client $client, UploadedFile $file, FilePurpose $purpose): ClaudeFile
    {
        $validationError = $this->validator->validate($file, $purpose);

        if ($validationError !== null) {
            throw new RuntimeException(
                json_encode(['type' => 'error', 'error' => ['type' => $validationError['code'], 'message' => $validationError['code']]]),
                $validationError['status'],
            );
        }

        $workspace = $this->workspaces->resolveForClient($client);

        $response = Http::withHeaders([
            'x-api-key' => $workspace->apiKey,
            'anthropic-version' => config('llm.claude.anthropic_version'),
            'anthropic-beta' => config('llm.claude.beta_headers.files_api'),
        ])
            ->timeout(config('llm.claude.timeouts.request'))
            ->attach('file', fopen($file->getRealPath(), 'rb'), $file->getClientOriginalName())
            ->attach('purpose', $purpose->value)
            ->post(config('llm.claude.endpoints.files'));

        $statusCode = $response->status();
        $rawBody = $response->body();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException($rawBody, $statusCode);
        }

        $body = $response->json();
        $fileId = 'file_' . Str::random(24);

        $record = DB::transaction(function () use ($body, $fileId, $client, $file, $purpose): FileRecord {
            $record = new FileRecord();
            $record->file_id = $fileId;
            $record->client_id = $client->id;
            $record->anthropic_file_id = $body['id'];
            $record->filename = $body['filename'] ?? $file->getClientOriginalName();
            $record->mime_type = $body['mime_type'] ?? (string) $file->getMimeType();
            $record->size_bytes = $body['size_bytes'] ?? $file->getSize();
            $record->upload_purpose = $purpose->value;
            $record->is_deleted = false;
            $record->save();

            return $record;
        });

        return ClaudeFile::fromRecord($record);
    }
}
