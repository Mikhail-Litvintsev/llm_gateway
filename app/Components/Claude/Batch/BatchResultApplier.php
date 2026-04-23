<?php

declare(strict_types=1);

namespace App\Components\Claude\Batch;

use App\Components\Claude\DTO\ResultLine;
use App\Components\Claude\Enums\BatchItemStatus;
use App\Components\Claude\Response\ResponseParser;
use App\Components\Logging\DTO\LoggingRecord;
use App\Components\Logging\Enums\Endpoint;
use App\Components\Logging\Enums\Mode;
use App\Components\Logging\Enums\RequestStatus;
use App\Components\Logging\Logging;
use App\Components\Pricing\CostCalculator;
use App\Models\BatchItem;
use App\Models\BatchRecord;
use DateTimeImmutable;
use Illuminate\Support\Str;

final readonly class BatchResultApplier
{
    public function __construct(
        private ResponseParser $responseParser,
        private CostCalculator $costCalculator,
        private Logging $logging,
    ) {}

    public function apply(ResultLine $line, BatchItem $item, BatchRecord $batch): float
    {
        $itemStatus = $this->mapStatus($line->type);

        $updateData = [
            'status' => $itemStatus->value,
            'result_payload' => json_encode(
                $line->message ?? $line->error ?? [],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        ];

        if ($itemStatus === BatchItemStatus::Errored && $line->error !== null) {
            $updateData['error_type'] = $line->error['type'] ?? 'unknown';
            $updateData['error_message'] = mb_substr($line->error['message'] ?? '', 0, 2000);
        }

        $costUsd = 0.0;
        $requestId = 'req_'.Str::random(24);

        $decoded = json_decode($item->payload, true, 512, JSON_THROW_ON_ERROR);
        $params = $decoded['params'] ?? [];
        $modelAlias = $this->resolveModelAlias($params['model'] ?? '');
        $modelSnapshot = $params['model'] ?? '';

        if ($itemStatus === BatchItemStatus::Succeeded && $line->message !== null) {
            $usage = $line->message['usage'] ?? [];
            $usageData = $this->responseParser->extractUsageData($usage);

            $client = $batch->client;
            $costBreakdown = $this->costCalculator->calculate(
                $usageData,
                $modelAlias,
                true,
                $client->inference_geo === 'us',
            );
            $costUsd = $costBreakdown->totalCost->toFloat();

            $this->logging->record(new LoggingRecord(
                requestId: $requestId,
                clientId: $batch->client_id,
                endpoint: Endpoint::BatchItem,
                mode: Mode::Batch,
                modelAlias: $modelAlias,
                modelSnapshot: $modelSnapshot,
                anthropicRequestId: $line->message['id'] ?? null,
                anthropicOrganizationId: null,
                status: RequestStatus::Completed,
                httpStatus: 200,
                errorType: null,
                errorMessage: null,
                serviceTierUsed: $usage['service_tier'] ?? null,
                createdAt: new DateTimeImmutable,
                startedAt: null,
                completedAt: new DateTimeImmutable,
                inputTokens: $usage['input_tokens'] ?? 0,
                outputTokens: $usage['output_tokens'] ?? 0,
                cacheReadTokens: $usage['cache_read_input_tokens'] ?? 0,
                thinkingTokens: $usage['thinking_tokens'] ?? 0,
                costUsd: number_format($costUsd, 8, '.', ''),
                costBreakdown: [
                    'input' => $costBreakdown->inputCost->toFloat(),
                    'output' => $costBreakdown->outputCost->toFloat(),
                    'cache_write_5m' => $costBreakdown->cacheWrite5mCost->toFloat(),
                    'cache_write_1h' => $costBreakdown->cacheWrite1hCost->toFloat(),
                    'cache_read' => $costBreakdown->cacheReadCost->toFloat(),
                ],
                requestPayload: $item->payload,
                responsePayload: json_encode($line->message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                retentionUntil: new DateTimeImmutable('+'.config('llm.raw_log_retention_days', 14).' days'),
            ));
        } else {
            $errorStatus = match ($itemStatus) {
                BatchItemStatus::Errored => RequestStatus::FailedClientError,
                BatchItemStatus::Cancelled => RequestStatus::FailedClientError,
                BatchItemStatus::Expired => RequestStatus::FailedServerError,
                default => RequestStatus::FailedServerError,
            };

            $this->logging->record(new LoggingRecord(
                requestId: $requestId,
                clientId: $batch->client_id,
                endpoint: Endpoint::BatchItem,
                mode: Mode::Batch,
                modelAlias: $modelAlias,
                modelSnapshot: $modelSnapshot,
                anthropicRequestId: null,
                anthropicOrganizationId: null,
                status: $errorStatus,
                httpStatus: null,
                errorType: $line->error['type'] ?? $line->type,
                errorMessage: $line->error['message'] ?? null,
                serviceTierUsed: null,
                createdAt: new DateTimeImmutable,
                startedAt: null,
                completedAt: new DateTimeImmutable,
                requestPayload: $item->payload,
                retentionUntil: new DateTimeImmutable('+'.config('llm.raw_log_retention_days', 14).' days'),
            ));
        }

        $updateData['request_id'] = $requestId;
        $item->update($updateData);

        return $costUsd;
    }

    private function mapStatus(string $type): BatchItemStatus
    {
        return match ($type) {
            'succeeded' => BatchItemStatus::Succeeded,
            'errored' => BatchItemStatus::Errored,
            'canceled' => BatchItemStatus::Cancelled,
            'expired' => BatchItemStatus::Expired,
            default => BatchItemStatus::Errored,
        };
    }

    private function resolveModelAlias(string $snapshot): string
    {
        $aliases = config('llm.claude.model_aliases', []);

        foreach ($aliases as $alias => $snapshotValue) {
            if ($snapshotValue === $snapshot) {
                return $alias;
            }
        }

        return $snapshot;
    }
}
