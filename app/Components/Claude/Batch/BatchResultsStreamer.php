<?php

declare(strict_types=1);

namespace App\Components\Claude\Batch;

use App\Components\Claude\Claude;
use App\Models\BatchRecord;
use Generator;
use Illuminate\Support\Facades\Log;

final class BatchResultsStreamer
{
    public function __construct(
        private readonly Claude $claude,
    ) {}

    /**
     * @return Generator<string>
     */
    public function stream(BatchRecord $batch): Generator
    {
        if ($batch->anthropic_batch_id !== null && $batch->results_url !== null) {
            yield from $this->streamFromAnthropic($batch);

            return;
        }

        yield from $this->streamFromDatabase($batch);
    }

    /**
     * @return Generator<string>
     */
    private function streamFromAnthropic(BatchRecord $batch): Generator
    {
        try {
            $client = $batch->client;
            $hasResults = false;

            foreach ($this->claude->getBatchResults($batch->anthropic_batch_id, $client) as $resultLine) {
                $hasResults = true;
                yield json_encode([
                    'custom_id' => $resultLine->customId,
                    'result' => [
                        'type' => $resultLine->type,
                        'message' => $resultLine->message,
                        'error' => $resultLine->error,
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }

            if ($hasResults) {
                return;
            }
        } catch (\Throwable $e) {
            Log::channel('llm')->warning('Anthropic results unavailable, falling back to DB', [
                'batch_id' => $batch->batch_id,
                'error' => $e->getMessage(),
            ]);
        }

        yield from $this->streamFromDatabase($batch);
    }

    /**
     * @return Generator<string>
     */
    private function streamFromDatabase(BatchRecord $batch): Generator
    {
        $batch->items()
            ->orderBy('id')
            ->chunk(500, function ($items) use (&$lines) {
                foreach ($items as $item) {
                    $resultPayload = $item->result_payload
                        ? json_decode($item->result_payload, true)
                        : null;

                    $lines[] = json_encode([
                        'custom_id' => $item->custom_id,
                        'result' => [
                            'type' => $this->mapStatusToType($item->status->value),
                            'message' => $item->status->value === 'succeeded' ? $resultPayload : null,
                            'error' => in_array($item->status->value, ['errored'], true) ? [
                                'type' => $item->error_type ?? 'unknown',
                                'message' => $item->error_message ?? '',
                            ] : null,
                        ],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
            });

        foreach ($lines ?? [] as $line) {
            yield $line;
        }
    }

    private function mapStatusToType(string $status): string
    {
        return match ($status) {
            'succeeded' => 'succeeded',
            'errored' => 'errored',
            'cancelled' => 'canceled',
            'expired' => 'expired',
            default => 'errored',
        };
    }
}
