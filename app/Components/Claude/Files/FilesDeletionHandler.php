<?php

declare(strict_types=1);

namespace App\Components\Claude\Files;

use App\Components\Routing\WorkspaceResolver;
use App\Models\Client;
use App\Models\FileRecord;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final readonly class FilesDeletionHandler
{
    public function __construct(
        private WorkspaceResolver $workspaces,
    ) {}

    public function delete(FileRecord $record, Client $client): void
    {
        $this->deleteOnAnthropic($record, $client);

        $record->update([
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);
    }

    private function deleteOnAnthropic(FileRecord $record, Client $client): void
    {
        $workspace = $this->workspaces->resolveForClient($client);
        $endpoint = config('llm.claude.endpoints.files') . '/' . $record->anthropic_file_id;

        $response = Http::withHeaders([
            'x-api-key' => $workspace->apiKey,
            'anthropic-version' => config('llm.claude.anthropic_version'),
            'anthropic-beta' => config('llm.claude.beta_headers.files_api'),
        ])
            ->timeout(config('llm.claude.timeouts.connect'))
            ->delete($endpoint);

        $status = $response->status();

        if (in_array($status, [200, 204, 404, 410], true)) {
            return;
        }

        if ($status >= 500) {
            throw new RuntimeException(
                json_encode([
                    'type' => 'error',
                    'error' => ['type' => 'provider_error', 'message' => 'Upstream provider error during file deletion'],
                ]),
                502,
            );
        }

        throw new RuntimeException(
            json_encode([
                'type' => 'error',
                'error' => ['type' => 'provider_error', 'message' => 'Unexpected response from provider: HTTP ' . $status],
            ]),
            502,
        );
    }
}
