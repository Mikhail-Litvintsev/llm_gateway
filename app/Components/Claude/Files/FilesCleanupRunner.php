<?php

declare(strict_types=1);

namespace App\Components\Claude\Files;

use App\Components\Routing\WorkspaceResolver;
use App\Models\FileRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class FilesCleanupRunner
{
    private const int MAX_HARD_DELETES_PER_RUN = 500;

    public function __construct(
        private readonly FilesUsageIndex $usageIndex,
        private readonly WorkspaceResolver $workspaces,
    ) {}

    public function runHardDeletePass(): int
    {
        $graceDays = (int) config('llm.claude.files.hard_delete_grace_days');

        $records = FileRecord::where('is_deleted', true)
            ->where('deleted_at', '<', now()->subDays($graceDays))
            ->limit(self::MAX_HARD_DELETES_PER_RUN)
            ->get();

        $deletedCount = 0;

        foreach ($records as $record) {
            $this->deleteFromAnthropic($record);

            DB::table('files')->where('id', $record->id)->delete();
            $deletedCount++;

            Log::channel('llm')->info('files.hard_deleted', [
                'file_id' => $record->file_id,
                'anthropic_file_id' => $record->anthropic_file_id,
                'client_id' => $record->client_id,
            ]);
        }

        return $deletedCount;
    }

    public function runUnusedAlertPass(): int
    {
        $unusedAlertDays = (int) config('llm.claude.files.unused_alert_days');

        $records = FileRecord::where('is_deleted', false)
            ->where('created_at', '<', now()->subDays($unusedAlertDays))
            ->get();

        $alertCount = 0;

        foreach ($records as $record) {
            if ($this->usageIndex->isReferenced($record)) {
                continue;
            }

            Log::channel('llm')->warning('files.unused_file_detected', [
                'file_id' => $record->file_id,
                'anthropic_file_id' => $record->anthropic_file_id,
                'client_id' => $record->client_id,
                'created_at' => $record->created_at,
                'age_days' => (int) now()->diffInDays($record->created_at),
            ]);

            $alertCount++;
        }

        return $alertCount;
    }

    private function deleteFromAnthropic(FileRecord $record): void
    {
        $client = $record->client;

        if (!$client) {
            return;
        }

        $workspace = $this->workspaces->resolveForClient($client);
        $endpoint = config('llm.claude.endpoints.files') . '/' . $record->anthropic_file_id;

        try {
            Http::withHeaders([
                'x-api-key' => $workspace->apiKey,
                'anthropic-version' => config('llm.claude.anthropic_version'),
                'anthropic-beta' => config('llm.claude.beta_headers.files_api'),
            ])
                ->timeout(config('llm.claude.timeouts.connect'))
                ->delete($endpoint);
        } catch (Throwable $e) {
            Log::channel('llm')->warning('files.anthropic_delete_failed', [
                'file_id' => $record->file_id,
                'anthropic_file_id' => $record->anthropic_file_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
