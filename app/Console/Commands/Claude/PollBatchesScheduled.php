<?php

declare(strict_types=1);

namespace App\Console\Commands\Claude;

use App\Components\Claude\Enums\BatchStatus;
use App\Components\Routing\WorkspaceResolver;
use App\Jobs\Claude\FetchBatchResults;
use App\Models\BatchRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class PollBatchesScheduled extends Command
{
    protected $signature = 'claude:poll-batches';

    protected $description = 'Poll Anthropic for in-progress batch statuses';

    public function handle(WorkspaceResolver $workspaces): int
    {
        $cooldownKey = 'claude:batch-poll-cooldown';

        if (Cache::has($cooldownKey)) {
            $this->info('Rate-limit cooldown active, skipping poll cycle.');

            return self::SUCCESS;
        }

        $batches = BatchRecord::where('status', BatchStatus::InProgress)
            ->orderBy('last_polled_at', 'asc')
            ->limit(200)
            ->get();

        foreach ($batches as $batch) {
            $this->pollOne($batch, $workspaces, $cooldownKey);
        }

        return self::SUCCESS;
    }

    private function pollOne(BatchRecord $batch, WorkspaceResolver $workspaces, string $cooldownKey): void
    {
        $client = $batch->client;

        if (!$client) {
            return;
        }

        $workspace = $workspaces->resolveForClient($client);

        $endpoint = config('llm.claude.endpoints.batches') . '/' . $batch->anthropic_batch_id;

        try {
            $response = Http::withHeaders([
                'x-api-key' => $workspace->apiKey,
                'anthropic-version' => config('llm.claude.anthropic_version'),
            ])
                ->timeout(config('llm.claude.timeouts.connect'))
                ->get($endpoint);
        } catch (\Throwable $e) {
            Log::channel('llm')->warning('Batch poll network error', [
                'batch_id' => $batch->batch_id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $statusCode = $response->status();

        if ($statusCode === 429) {
            $retryAfter = (int) ($response->header('retry-after') ?: 60);
            Cache::put($cooldownKey, true, $retryAfter);
            Log::channel('llm')->warning('Batch poll rate-limited', ['retry_after' => $retryAfter]);

            return;
        }

        if ($statusCode === 404) {
            $batch->update(['status' => BatchStatus::Failed]);
            Log::channel('llm')->error('Batch not found on Anthropic', [
                'batch_id' => $batch->batch_id,
                'anthropic_batch_id' => $batch->anthropic_batch_id,
            ]);

            return;
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            Log::channel('llm')->warning('Batch poll unexpected status', [
                'batch_id' => $batch->batch_id,
                'status' => $statusCode,
            ]);

            $batch->update([
                'last_polled_at' => now(),
                'poll_attempts' => $batch->poll_attempts + 1,
            ]);

            return;
        }

        $body = $response->json();
        $processingStatus = $body['processing_status'] ?? 'unknown';

        if ($processingStatus === 'ended') {
            $batch->update([
                'results_url' => $body['results_url'] ?? null,
                'last_polled_at' => now(),
                'poll_attempts' => $batch->poll_attempts + 1,
                'succeeded_count' => $body['request_counts']['succeeded'] ?? 0,
                'errored_count' => $body['request_counts']['errored'] ?? 0,
                'cancelled_count' => $body['request_counts']['canceled'] ?? 0,
                'expired_count' => $body['request_counts']['expired'] ?? 0,
            ]);

            FetchBatchResults::dispatch($batch->batch_id)
                ->onQueue(config('llm.queues.batch'));

            $this->info("Batch {$batch->batch_id} ended, dispatched FetchBatchResults.");

            return;
        }

        $batch->update([
            'last_polled_at' => now(),
            'poll_attempts' => $batch->poll_attempts + 1,
        ]);
    }
}
