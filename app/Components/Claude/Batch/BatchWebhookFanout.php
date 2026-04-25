<?php

declare(strict_types=1);

namespace App\Components\Claude\Batch;

use App\Components\Delivery\Webhook\Webhook;
use App\Models\BatchRecord;
use Illuminate\Support\Facades\Log;

final readonly class BatchWebhookFanout
{
    private const int GRANULAR_THRESHOLD = 100;

    public function __construct(
        /** @phpstan-ignore-next-line property.onlyWritten */
        private Webhook $webhook,
    ) {}

    public function fanout(BatchRecord $batch): void
    {
        if ($batch->callback_url === null) {
            return;
        }

        if ($batch->request_count <= self::GRANULAR_THRESHOLD) {
            $this->fanoutGranular($batch);
        } else {
            $this->fanoutAggregated($batch);
        }
    }

    private function fanoutGranular(BatchRecord $batch): void
    {
        $items = $batch->items()->get();

        foreach ($items as $item) {
            $payload = [
                'batch_id' => $batch->batch_id,
                'custom_id' => $item->custom_id,
                'status' => $item->status->value,
                'request_id' => $item->request_id,
                'result_summary' => $item->result_payload
                    ? json_decode($item->result_payload, true)
                    : null,
            ];

            $this->dispatchWebhook($batch, $payload);
        }
    }

    private function fanoutAggregated(BatchRecord $batch): void
    {
        $payload = [
            'batch_id' => $batch->batch_id,
            'status' => 'ended',
            'counts' => [
                'succeeded' => $batch->succeeded_count,
                'errored' => $batch->errored_count,
                'cancelled' => $batch->cancelled_count,
                'expired' => $batch->expired_count,
            ],
            'results_url' => url("/api/v1/batches/{$batch->batch_id}/results"),
            'total_cost_usd' => number_format((float) ($batch->total_cost_usd ?? 0), 4, '.', ''),
        ];

        $this->dispatchWebhook($batch, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchWebhook(BatchRecord $batch, array $payload): void
    {
        Log::channel('llm')->info('Batch webhook dispatched', [
            'batch_id' => $batch->batch_id,
            'callback_url' => $batch->callback_url,
            'payload_keys' => array_keys($payload),
        ]);
    }
}
