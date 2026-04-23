<?php

declare(strict_types=1);

namespace App\Components\Claude\Batch\Accumulator;

use App\Components\Claude\Batch\Accumulator\DTO\AppendResult;
use App\Components\Claude\Batch\Accumulator\Exceptions\CallbackUrlMismatchException;
use App\Components\Claude\Batch\Accumulator\Exceptions\DuplicateCustomIdException;
use App\Components\Routing\ModelResolver;
use App\Jobs\Claude\FlushBatchAccumulatorBucket;
use App\Models\Client;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

final class BatchAccumulator
{
    private static ?string $appendScriptSha = null;

    public function __construct(
        private readonly ModelResolver $modelResolver,
        private readonly FlushTriggerEvaluator $triggerEvaluator,
    ) {}

    public function append(
        Client $client,
        array $itemPayload,
        ?string $customId,
        ?string $callbackUrl,
        string $flushStrategy,
    ): AppendResult {
        $modelAlias = $itemPayload['model'] ?? config('llm.claude.default_model_alias');
        $this->modelResolver->resolve($modelAlias);

        $customId ??= (string) Str::uuid();

        $bucketKey = new AccumulatorBucketKey($client->id, $modelAlias);

        $itemJson = json_encode($itemPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $itemBytes = strlen($itemJson);

        $result = $this->runAppendScript($bucketKey, $itemJson, $customId, $itemBytes);

        $status = (int) $result[0];
        $position = (int) $result[1];
        $shouldFlush = (int) $result[2];

        if ($status === -1) {
            throw new DuplicateCustomIdException($customId);
        }

        $this->ensureCallbackUrlConsistency($bucketKey, $callbackUrl);

        if ($shouldFlush === 1 || $flushStrategy === 'immediate') {
            FlushBatchAccumulatorBucket::dispatch($bucketKey->key)
                ->onQueue(config('llm.queues.batch'));
        }

        return new AppendResult(
            bucketId: $bucketKey->key,
            position: $position,
            customId: $customId,
        );
    }

    private function runAppendScript(
        AccumulatorBucketKey $bucketKey,
        string $itemJson,
        string $customId,
        int $itemBytes,
    ): array {
        $keys = [
            $bucketKey->key,
            $bucketKey->metaKey(),
            $bucketKey->idsKey(),
            AccumulatorBucketKey::pendingSetKey(),
        ];

        $args = [
            $itemJson,
            $customId,
            (string) $itemBytes,
            (string) time(),
            (string) $this->triggerEvaluator->triggerCount(),
            (string) $this->triggerEvaluator->triggerBytes(),
            (string) $this->triggerEvaluator->triggerSeconds(),
        ];

        $sha = self::$appendScriptSha;

        if ($sha !== null) {
            try {
                return Redis::connection('default')->evalsha($sha, 4, ...$keys, ...$args);
            } catch (\Throwable) {
                self::$appendScriptSha = null;
            }
        }

        $script = $this->loadAppendScript();

        try {
            self::$appendScriptSha = Redis::connection('default')->script('load', $script);

            return Redis::connection('default')->evalsha(self::$appendScriptSha, 4, ...$keys, ...$args);
        } catch (\Throwable) {
            return Redis::connection('default')->eval($script, 4, ...$keys, ...$args);
        }
    }

    private function loadAppendScript(): string
    {
        return file_get_contents(__DIR__.'/lua/append_and_maybe_trigger.lua');
    }

    private function ensureCallbackUrlConsistency(AccumulatorBucketKey $bucketKey, ?string $callbackUrl): void
    {
        if ($callbackUrl === null) {
            return;
        }

        $metaKey = $bucketKey->metaKey();
        $existing = Redis::connection('default')->hget($metaKey, 'callback_url');

        if ($existing === null || $existing === false) {
            Redis::connection('default')->hset($metaKey, 'callback_url', $callbackUrl);

            return;
        }

        if ((string) $existing !== $callbackUrl) {
            throw new CallbackUrlMismatchException;
        }
    }
}
