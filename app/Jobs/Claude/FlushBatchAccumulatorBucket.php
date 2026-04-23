<?php

declare(strict_types=1);

namespace App\Jobs\Claude;

use App\Components\Claude\Batch\Accumulator\AccumulatorBucketKey;
use App\Components\Claude\Claude;
use App\Components\Claude\DTO\BatchCreateRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

final class FlushBatchAccumulatorBucket implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(
        public readonly string $bucketKey,
    ) {}

    public function handle(Claude $claude): void
    {
        $lockKey = AccumulatorBucketKey::lockKey($this->bucketKey);
        $lock = Redis::connection('default')->set($lockKey, '1', 'EX', 60, 'NX');

        if (!$lock) {
            return;
        }

        try {
            $raw = $this->atomicFlush();

            $items = $this->parseFlushResult($raw);

            if ($items === []) {
                return;
            }

            $clientId = AccumulatorBucketKey::parseClientId($this->bucketKey);
            $callbackUrl = $this->extractCallbackUrl($raw);

            $requests = array_map(
                fn (array $item): array => [
                    'custom_id' => $item['custom_id'] ?? $item['model'] . '-' . uniqid(),
                    'params' => $item,
                ],
                $items,
            );

            $batchRequest = new BatchCreateRequest(
                requests: $requests,
                submitImmediately: true,
                callbackUrl: $callbackUrl,
                autoUse1hCache: config('llm.claude.batch.auto_use_1h_cache_for_batch'),
            );

            $batch = $claude->createBatch($batchRequest, $clientId);

            Log::channel('llm')->info('Accumulator bucket flushed', [
                'bucket_key' => $this->bucketKey,
                'batch_id' => $batch->batchId,
                'item_count' => count($items),
            ]);
        } finally {
            Redis::connection('default')->del($lockKey);
        }
    }

    private function atomicFlush(): array
    {
        $keys = [
            $this->bucketKey,
            $this->bucketKey . ':meta',
            $this->bucketKey . ':ids',
            AccumulatorBucketKey::pendingSetKey(),
        ];

        $script = file_get_contents(
            __DIR__ . '/../../Components/Claude/Batch/Accumulator/lua/flush_bucket.lua'
        );

        return Redis::connection('default')->eval($script, 4, ...$keys);
    }

    private function parseFlushResult(array $raw): array
    {
        $items = [];
        $metaSeparatorIndex = array_search('---META---', $raw, true);

        if ($metaSeparatorIndex === false) {
            foreach ($raw as $jsonStr) {
                $items[] = json_decode($jsonStr, true, 512, JSON_THROW_ON_ERROR);
            }

            return $items;
        }

        for ($i = 0; $i < $metaSeparatorIndex; $i++) {
            $items[] = json_decode($raw[$i], true, 512, JSON_THROW_ON_ERROR);
        }

        return $items;
    }

    private function extractCallbackUrl(array $raw): ?string
    {
        $metaSeparatorIndex = array_search('---META---', $raw, true);

        if ($metaSeparatorIndex === false) {
            return null;
        }

        $metaPairs = array_slice($raw, $metaSeparatorIndex + 1);

        for ($i = 0; $i < count($metaPairs) - 1; $i += 2) {
            if ($metaPairs[$i] === 'callback_url') {
                return $metaPairs[$i + 1];
            }
        }

        return null;
    }
}
