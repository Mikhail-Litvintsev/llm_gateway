<?php

declare(strict_types=1);

namespace App\Components\Claude\Batch;

use App\Components\Claude\DTO\Batch;
use App\Components\Claude\Enums\BatchItemStatus;
use App\Components\Claude\Enums\BatchStatus;
use App\Components\Routing\WorkspaceResolver;
use App\Models\BatchRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final readonly class BatchCanceler
{
    public function __construct(
        private WorkspaceResolver $workspaces,
    ) {}

    public function cancel(BatchRecord $batch): Batch
    {
        if (! in_array($batch->status, [BatchStatus::InProgress, BatchStatus::Submitting], true)) {
            abort(response()->json([
                'type' => 'error',
                'error' => ['type' => 'cannot_cancel_in_terminal_state', 'message' => 'Batch cannot be cancelled in its current state'],
            ], 409));
        }

        if ($batch->anthropic_batch_id === null) {
            $batch->update(['status' => BatchStatus::Cancelled]);
            $batch->items()->update([
                'status' => BatchItemStatus::Cancelled->value,
            ]);

            return Batch::fromRecord($batch->fresh());
        }

        $client = $batch->client;
        $workspace = $this->workspaces->resolveForClient($client);
        $endpoint = config('llm.claude.endpoints.batches').'/'.$batch->anthropic_batch_id.'/cancel';

        $response = Http::withHeaders([
            'x-api-key' => $workspace->apiKey,
            'anthropic-version' => config('llm.claude.anthropic_version'),
        ])
            ->timeout(config('llm.claude.timeouts.connect'))
            ->post($endpoint);

        if ($response->status() === 404) {
            $batch->update(['status' => BatchStatus::Cancelled]);
            $batch->items()->update([
                'status' => BatchItemStatus::Cancelled->value,
            ]);

            return Batch::fromRecord($batch->fresh());
        }

        if ($response->successful()) {
            $batch->update(['status' => BatchStatus::Canceling]);

            return Batch::fromRecord($batch->fresh());
        }

        Log::channel('llm')->error('Failed to cancel batch on Anthropic', [
            'batch_id' => $batch->batch_id,
            'status' => $response->status(),
        ]);

        abort(response()->json([
            'type' => 'error',
            'error' => ['type' => 'upstream_error', 'message' => 'Failed to cancel batch on Anthropic'],
        ], 502));
    }
}
