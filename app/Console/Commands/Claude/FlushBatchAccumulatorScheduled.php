<?php

declare(strict_types=1);

namespace App\Console\Commands\Claude;

use App\Components\Claude\Batch\Accumulator\AccumulatorBucketKey;
use App\Components\Claude\Batch\Accumulator\FlushTriggerEvaluator;
use App\Jobs\Claude\FlushBatchAccumulatorBucket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

final class FlushBatchAccumulatorScheduled extends Command
{
    protected $signature = 'claude:flush-accumulator';

    protected $description = 'Flush batch accumulator buckets that have reached their trigger thresholds';

    public function handle(FlushTriggerEvaluator $evaluator): int
    {
        $this->dispatchPendingBuckets();
        $this->scanForAgedBuckets($evaluator);

        return self::SUCCESS;
    }

    private function dispatchPendingBuckets(): void
    {
        $pendingKey = AccumulatorBucketKey::pendingSetKey();
        $bucketKeys = Redis::connection('default')->smembers($pendingKey);

        foreach ($bucketKeys as $bucketKey) {
            FlushBatchAccumulatorBucket::dispatch((string) $bucketKey)
                ->onQueue(config('llm.queues.batch'));
        }
    }

    private function scanForAgedBuckets(FlushTriggerEvaluator $evaluator): void
    {
        $pendingKey = AccumulatorBucketKey::pendingSetKey();
        $cursor = '0';
        $now = time();
        $maxAge = $evaluator->triggerSeconds();

        do {
            $scanResult = Redis::connection('default')->scan(
                $cursor,
                'MATCH',
                'acc:*:meta',
                'COUNT',
                100,
            );

            if (! is_array($scanResult)) {
                break;
            }

            [$cursor, $keys] = $scanResult;

            foreach ($keys as $metaKey) {
                $firstAppendAt = Redis::connection('default')->hget($metaKey, 'first_append_at');

                if ($firstAppendAt === null || $firstAppendAt === false) {
                    continue;
                }

                $age = $now - (int) $firstAppendAt;

                if ($age < $maxAge) {
                    continue;
                }

                $bucketKey = str_replace(':meta', '', (string) $metaKey);
                Redis::connection('default')->sadd($pendingKey, $bucketKey);

                FlushBatchAccumulatorBucket::dispatch($bucketKey)
                    ->onQueue(config('llm.queues.batch'));
            }
        } while ((string) $cursor !== '0');
    }
}
