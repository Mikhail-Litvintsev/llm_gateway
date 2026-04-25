<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Components\Claude\Batch\BatchCanceler;
use App\Components\Claude\Batch\BatchResultsStreamer;
use App\Components\Claude\Claude;
use App\Components\Claude\DTO\BatchCreateRequest;
use App\Components\Claude\Enums\BatchStatus;
use App\Components\Routing\WorkspaceResolver;
use App\Http\Controllers\Controller;
use App\Models\BatchRecord;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BatchesController extends Controller
{
    public function __construct(
        private readonly Claude $claude,
        private readonly BatchCanceler $canceler,
        private readonly BatchResultsStreamer $resultsStreamer,
        private readonly WorkspaceResolver $workspaces,
    ) {}

    public function create(Request $request): JsonResponse
    {
        $client = $this->resolveClient($request);

        $dto = BatchCreateRequest::fromArray($request->json()->all());
        $batch = $this->claude->createBatch($dto, (int) $client->id);

        return response()->json($batch->toArray(), 201);
    }

    public function show(Request $request, string $batchId): JsonResponse
    {
        $client = $this->resolveClient($request);
        $batch = $this->findBatchOrFail($batchId, $client);

        return response()->json($this->buildShowResponse($batch));
    }

    public function results(Request $request, string $batchId): StreamedResponse
    {
        $client = $this->resolveClient($request);
        $batch = $this->findBatchOrFail($batchId, $client);

        if (! in_array($batch->status, [BatchStatus::Ended, BatchStatus::Cancelled, BatchStatus::Failed, BatchStatus::Expired], true)) {
            abort(response()->json([
                'type' => 'error',
                'error' => ['type' => 'batch_not_ended', 'message' => 'Batch results are not available until the batch has ended'],
            ], 409));
        }

        return response()->stream(function () use ($batch): void {
            foreach ($this->resultsStreamer->stream($batch) as $ndjsonLine) {
                echo $ndjsonLine."\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        }, 200, ['Content-Type' => 'application/x-ndjson']);
    }

    public function cancel(Request $request, string $batchId): JsonResponse
    {
        $client = $this->resolveClient($request);
        $batch = $this->findBatchOrFail($batchId, $client);

        $this->canceler->cancel($batch);

        return response()->json($this->buildShowResponse($batch->fresh()));
    }

    public function destroy(Request $request, string $batchId): Response
    {
        $client = $this->resolveClient($request);
        $batch = $this->findBatchOrFail($batchId, $client);

        $terminalStatuses = [BatchStatus::Ended, BatchStatus::Cancelled, BatchStatus::Expired, BatchStatus::Failed];

        if (! in_array($batch->status, $terminalStatuses, true)) {
            abort(response()->json([
                'type' => 'error',
                'error' => ['type' => 'cannot_delete_in_flight_batch', 'message' => 'Only terminal batches can be deleted'],
            ], 409));
        }

        if ($batch->anthropic_batch_id !== null) {
            $this->deleteOnAnthropic($batch, $client);
        }

        $batch->update(['deleted_at' => now()]);

        return response()->noContent();
    }

    public function index(Request $request): JsonResponse
    {
        $client = $this->resolveClient($request);

        $limit = min((int) $request->query('limit', '50'), 200);
        $statusFilter = $request->query('status');
        $cursor = $request->query('cursor');

        $query = BatchRecord::where('client_id', $client->id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($statusFilter !== null) {
            $query->where('status', $statusFilter);
        }

        if ($cursor !== null) {
            $decoded = json_decode(base64_decode($cursor, true) ?: '', true);

            if (is_array($decoded) && isset($decoded['created_at'], $decoded['id'])) {
                $query->where(function ($q) use ($decoded): void {
                    $q->where('created_at', '<', $decoded['created_at'])
                        ->orWhere(function ($q2) use ($decoded): void {
                            $q2->where('created_at', '=', $decoded['created_at'])
                                ->where('id', '<', $decoded['id']);
                        });
                });
            }
        }

        $batches = $query->limit($limit + 1)->get();

        $hasMore = $batches->count() > $limit;
        $batches = $batches->take($limit);

        $nextCursor = null;
        if ($hasMore && $batches->isNotEmpty()) {
            $last = $batches->last();
            $nextCursor = base64_encode(json_encode([
                'created_at' => $last->created_at->toIso8601String(),
                'id' => $last->id,
            ]));
        }

        $items = $batches->map(fn (BatchRecord $b) => $this->buildShowResponse($b))->values()->all();

        return response()->json([
            'batches' => $items,
            'next_cursor' => $nextCursor,
        ]);
    }

    private function resolveClient(Request $request): Client
    {
        $client = $request->attributes->get('auth.client');
        assert($client instanceof Client);

        return $client;
    }

    private function findBatchOrFail(string $batchId, Client $client): BatchRecord
    {
        $batch = BatchRecord::where('batch_id', $batchId)
            ->where('client_id', $client->id)
            ->first();

        if ($batch === null) {
            abort(response()->json([
                'type' => 'error',
                'error' => ['type' => 'not_found_error', 'message' => 'Batch not found'],
            ], 404));
        }

        return $batch;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildShowResponse(BatchRecord $batch): array
    {
        return [
            'batch_id' => $batch->batch_id,
            'anthropic_batch_id' => $batch->anthropic_batch_id,
            'status' => $batch->status->value,
            'request_count' => $batch->request_count,
            'counts' => [
                'succeeded' => $batch->succeeded_count,
                'errored' => $batch->errored_count,
                'cancelled' => $batch->cancelled_count,
                'expired' => $batch->expired_count,
            ],
            'submitted_at' => $batch->submitted_at?->toIso8601String(),
            'completed_at' => $batch->completed_at?->toIso8601String(),
            'results_url' => $batch->results_url,
            'total_cost_usd' => $batch->total_cost_usd ?? '0.0000',
            'cache_hit_ratio' => $batch->cache_hit_ratio ?? null,
            'total_savings_from_caching_usd' => $batch->total_savings_from_caching_usd ?? null,
        ];
    }

    private function deleteOnAnthropic(BatchRecord $batch, Client $client): void
    {
        $workspace = $this->workspaces->resolveForClient($client);
        $endpoint = config('llm.claude.endpoints.batches').'/'.$batch->anthropic_batch_id;

        try {
            Http::withHeaders([
                'x-api-key' => $workspace->apiKey,
                'anthropic-version' => config('llm.claude.anthropic_version'),
            ])
                ->timeout(config('llm.claude.timeouts.connect'))
                ->delete($endpoint);
        } catch (\Throwable $e) {
            Log::channel('llm')->warning('Failed to delete batch on Anthropic', [
                'batch_id' => $batch->batch_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
