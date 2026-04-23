<?php

declare(strict_types=1);

namespace App\Components\Claude\Batch;

use App\Components\Caching\AutoCacheInjector;
use App\Components\Claude\Payload\PayloadBuilder;
use App\Models\BatchRecord;
use App\Models\Client;

final readonly class BatchPayloadBuilder
{
    public function __construct(
        private readonly AutoCacheInjector $autoCacheInjector,
        private readonly PayloadBuilder $payloadBuilder,
    ) {}

    /**
     * @return array{requests: array<int, array<string, mixed>>}
     */
    public function build(BatchRecord $batch, Client $client): array
    {
        $items = $batch->items()->get();
        $requests = [];

        foreach ($items as $item) {
            $decoded = json_decode($item->payload, true, 512, JSON_THROW_ON_ERROR);
            $params = $decoded['params'];

            $params = $this->autoCacheInjector->injectForBatchItem(
                $params,
                $params['model'],
                $batch->auto_use_1h_cache,
            );

            $built = $this->payloadBuilder->build($params, $client);

            $requests[] = [
                'custom_id' => $decoded['custom_id'],
                'params' => json_decode($built->jsonBody, true, 512, JSON_THROW_ON_ERROR),
            ];
        }

        return ['requests' => $requests];
    }
}
