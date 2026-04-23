<?php

declare(strict_types=1);

namespace App\Components\Claude\Batch;

use App\Components\Claude\DTO\BatchCreateRequest;
use App\Components\Claude\Enums\BatchItemStatus;
use App\Components\Claude\Enums\BatchStatus;
use App\Components\Routing\ModelResolver;
use App\Models\BatchRecord;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class BatchPersister
{
    public function __construct(
        private readonly ModelResolver $modelResolver,
    ) {}

    public function persist(BatchCreateRequest $request, Client $client): BatchRecord
    {
        $batchId = 'bat_'.Str::random(24);
        $autoUseCache = $request->autoUse1hCache ?? (bool) config('llm.claude.batch.auto_use_1h_cache_for_batch', true);

        return DB::transaction(function () use ($batchId, $request, $client, $autoUseCache): BatchRecord {
            $record = new BatchRecord;
            $record->batch_id = $batchId;
            $record->client_id = $client->id;
            $record->status = BatchStatus::Created;
            $record->request_count = count($request->requests);
            $record->callback_url = $request->callbackUrl;
            $record->auto_use_1h_cache = $autoUseCache;
            $record->save();

            $rows = [];
            foreach ($request->requests as $item) {
                $params = $item['params'];
                $alias = $params['model'] ?? config('llm.claude.default_model_alias');
                $resolved = $this->modelResolver->resolve($alias);

                $rows[] = [
                    'batch_id' => $record->id,
                    'custom_id' => $item['custom_id'],
                    'payload' => json_encode([
                        'custom_id' => $item['custom_id'],
                        'params' => array_merge($params, ['model' => $resolved->snapshot]),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'status' => BatchItemStatus::Pending->value,
                ];
            }

            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('batch_items')->insert($chunk);
            }

            return $record;
        });
    }
}
