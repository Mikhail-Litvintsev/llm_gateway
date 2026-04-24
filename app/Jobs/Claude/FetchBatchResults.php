<?php

declare(strict_types=1);

namespace App\Jobs\Claude;

use App\Components\Claude\Batch\BatchCacheMetrics;
use App\Components\Claude\Batch\BatchResultApplier;
use App\Components\Claude\Batch\BatchResultParser;
use App\Components\Claude\Batch\BatchWebhookFanout;
use App\Components\Claude\DTO\UsageData;
use App\Components\Claude\Enums\BatchStatus;
use App\Components\Claude\Response\ResponseParser;
use App\Components\Routing\WorkspaceResolver;
use App\Models\BatchRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class FetchBatchResults implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $batchId) {}

    public function handle(
        WorkspaceResolver $workspaces,
        BatchResultParser $parser,
        BatchResultApplier $applier,
        BatchWebhookFanout $fanout,
        BatchCacheMetrics $cacheMetrics,
        ResponseParser $responseParser,
    ): void {
        $batch = BatchRecord::where('batch_id', $this->batchId)->firstOrFail();

        if (! in_array($batch->status, [BatchStatus::InProgress, BatchStatus::Fetching], true)) {
            return;
        }

        $batch->update(['status' => BatchStatus::Fetching]);
        $batch->load('client');

        try {
            [$totalCost, $usageItems] = $this->processResults($batch, $workspaces, $parser, $applier, $responseParser);
            $this->finalizeBatch($batch, $totalCost, $cacheMetrics, $usageItems);
            $fanout->fanout($batch->fresh());
        } catch (\Throwable $e) {
            $batch->update(['status' => BatchStatus::Failed]);

            Log::channel('llm')->error('FetchBatchResults failed', [
                'batch_id' => $this->batchId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{0: float, 1: list<UsageData>}
     */
    private function processResults(
        BatchRecord $batch,
        WorkspaceResolver $workspaces,
        BatchResultParser $parser,
        BatchResultApplier $applier,
        ResponseParser $responseParser,
    ): array {
        $workspace = $workspaces->resolveForClient($batch->client);
        $endpoint = config('llm.claude.endpoints.batches').'/'.$batch->anthropic_batch_id.'/results';

        $response = Http::withHeaders([
            'x-api-key' => $workspace->apiKey,
            'anthropic-version' => config('llm.claude.anthropic_version'),
            'accept' => 'application/x-ndjson',
        ])
            ->timeout(config('llm.claude.timeouts.request'))
            ->connectTimeout(config('llm.claude.timeouts.connect'))
            ->get($endpoint);

        $totalCost = 0.0;
        $usageItems = [];
        $body = $response->body();
        $lines = explode("\n", $body);

        $itemsByCustomId = $batch->items()->get()->keyBy('custom_id');

        foreach ($lines as $line) {
            $resultLine = $parser->parseLine($line);

            if ($resultLine === null) {
                continue;
            }

            $item = $itemsByCustomId->get($resultLine->customId);

            if ($item === null) {
                Log::channel('llm')->warning('Batch result for unknown custom_id', [
                    'batch_id' => $batch->batch_id,
                    'custom_id' => $resultLine->customId,
                ]);

                continue;
            }

            $totalCost += $applier->apply($resultLine, $item, $batch);

            if ($resultLine->type === 'succeeded' && $resultLine->message !== null) {
                $usageItems[] = $responseParser->extractUsageData($resultLine->message['usage'] ?? []);
            }
        }

        return [$totalCost, $usageItems];
    }

    /**
     * @param  list<UsageData>  $usageItems
     */
    private function finalizeBatch(
        BatchRecord $batch,
        float $totalCost,
        BatchCacheMetrics $cacheMetrics,
        array $usageItems,
    ): void {
        $counts = DB::table('batch_items')
            ->where('batch_id', $batch->id)
            ->selectRaw("
                SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) as succeeded_count,
                SUM(CASE WHEN status = 'errored' THEN 1 ELSE 0 END) as errored_count,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
                SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired_count
            ")
            ->first();

        $modelAlias = $this->resolveModelAliasFromBatch($batch);
        $pricingTier = config('llm.claude.pricing.'.$modelAlias, []);
        $metrics = $cacheMetrics->compute($usageItems, $pricingTier);

        $batch->update([
            'status' => BatchStatus::Ended,
            'completed_at' => now(),
            'succeeded_count' => (int) ($counts->succeeded_count ?? 0),
            'errored_count' => (int) ($counts->errored_count ?? 0),
            'cancelled_count' => (int) ($counts->cancelled_count ?? 0),
            'expired_count' => (int) ($counts->expired_count ?? 0),
            'total_cost_usd' => number_format($totalCost, 4, '.', ''),
            'cache_hit_ratio' => $metrics->cacheHitRatio,
            'total_savings_from_caching_usd' => $metrics->totalSavingsFromCachingUsd,
            'total_cache_read_tokens' => $metrics->totalCacheReadTokens,
            'total_input_tokens' => $metrics->totalInputTokens,
            'total_output_tokens' => $metrics->totalOutputTokens,
        ]);

        if ($totalCost > 0) {
            DB::table('clients')
                ->where('id', $batch->client_id)
                ->increment('current_month_spend_usd', $totalCost);
        }
    }

    private function resolveModelAliasFromBatch(BatchRecord $batch): string
    {
        $firstItem = $batch->items()->first();

        if ($firstItem === null) {
            return 'sonnet';
        }

        $decoded = json_decode($firstItem->payload, true, 512, JSON_THROW_ON_ERROR);
        $model = $decoded['params']['model'] ?? '';
        $aliases = config('llm.claude.model_aliases', []);

        foreach ($aliases as $alias => $snapshot) {
            if ($snapshot === $model) {
                return $alias;
            }
        }

        return $model ?: 'sonnet';
    }
}
