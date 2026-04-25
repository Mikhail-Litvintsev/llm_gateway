<?php

declare(strict_types=1);

namespace App\Components\Logging;

use App\Components\Claude\DTO\SendMessageOutput;
use App\Components\Logging\DTO\LoggingRecord;
use App\Components\Logging\DTO\LoggingResult;
use App\Components\Logging\Enums\RequestStatus;
use App\Components\Logging\Exceptions\IdempotencyException;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class Logging
{
    /**
     * Atomically writes requests, request_usage, and request_raw in a single transaction.
     *
     * All three INSERTs succeed or none do. On duplicate request_id, throws IdempotencyException.
     * On any other failure, the exception bubbles up — caller handles HTTP 500 and error logging.
     *
     * @throws IdempotencyException
     * @throws Throwable
     */
    public function record(LoggingRecord $record): LoggingResult
    {
        try {
            return DB::transaction(function () use ($record): LoggingResult {
                DB::table('requests')->insert([
                    'request_id' => $record->requestId,
                    'client_id' => $record->clientId,
                    'endpoint' => $record->endpoint->value,
                    'mode' => $record->mode->value,
                    'model_alias' => $record->modelAlias,
                    'model_snapshot' => $record->modelSnapshot,
                    'anthropic_request_id' => $record->anthropicRequestId,
                    'anthropic_organization_id' => $record->anthropicOrganizationId,
                    'status' => $record->status->value,
                    'http_status' => $record->httpStatus,
                    'error_type' => $record->errorType,
                    'error_message' => $record->errorMessage,
                    'service_tier_used' => $record->serviceTierUsed,
                    'created_at' => $record->createdAt->format('Y-m-d H:i:s'),
                    'started_at' => $record->startedAt?->format('Y-m-d H:i:s'),
                    'completed_at' => $record->completedAt?->format('Y-m-d H:i:s'),
                ]);

                DB::table('request_usage')->insert([
                    'request_id' => $record->requestId,
                    'input_tokens' => $record->inputTokens,
                    'output_tokens' => $record->outputTokens,
                    'cache_creation_5m_tokens' => $record->cacheCreation5mTokens,
                    'cache_creation_1h_tokens' => $record->cacheCreation1hTokens,
                    'cache_read_tokens' => $record->cacheReadTokens,
                    'thinking_tokens' => $record->thinkingTokens,
                    'server_tool_web_search_count' => $record->serverToolWebSearchCount,
                    'server_tool_web_fetch_count' => $record->serverToolWebFetchCount,
                    'server_tool_code_exec_count' => $record->serverToolCodeExecCount,
                    'server_tool_tool_search_count' => $record->serverToolToolSearchCount,
                    'cost_usd' => $record->costUsd,
                    'cost_breakdown' => json_encode($record->costBreakdown),
                    'iterations_json' => $record->iterationsJson !== null ? json_encode($record->iterationsJson) : null,
                    'rate_limit_headers' => $record->rateLimitHeaders !== null ? json_encode($record->rateLimitHeaders) : null,
                ]);

                DB::table('request_raw')->insert([
                    'request_id' => $record->requestId,
                    'request_payload' => PayloadMasker::mask($record->requestPayload),
                    'response_payload' => $record->responsePayload !== null ? PayloadMasker::mask($record->responsePayload) : null,
                    'retention_until' => $record->retentionUntil->format('Y-m-d H:i:s'),
                ]);

                return new LoggingResult($record->requestId);
            });
        } catch (QueryException $e) {
            if ($this->isDuplicateKeyException($e)) {
                throw new IdempotencyException($record->requestId);
            }

            throw $e;
        }
    }

    /**
     * Updates an existing requests row and inserts request_usage + request_raw for async flows.
     *
     * Unlike record() which INSERTs all three rows, this method UPDATEs the requests row
     * (created at 202-acceptance time) and INSERTs usage + raw data.
     *
     * @param  array<string, mixed>  $decodedPayload
     * @param  list<string>  $features
     *
     * @throws Throwable
     */
    public function updateAsyncRecord(
        string $requestId,
        SendMessageOutput $output,
        array $decodedPayload,
        array $features,
    ): void {
        $retentionDays = (int) config('llm.raw_log_retention_days', 14);
        $retentionUntil = new DateTimeImmutable("+$retentionDays days");

        $maskedRequestPayload = PayloadMasker::mask(
            json_encode($decodedPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        if ($output->isSuccess) {
            $this->persistSuccessRawRecord(
                $requestId,
                $maskedRequestPayload,
                $output,
                $retentionUntil,
            );
        }

        DB::transaction(function () use ($requestId, $output, $maskedRequestPayload, $retentionUntil): void {
            $this->updateRequestRow($requestId, $output);
            $this->insertUsageRow($requestId, $output);

            if (! $output->isSuccess) {
                $this->insertFailureRawRecord($requestId, $maskedRequestPayload, $retentionUntil);
            }
        });
    }

    public function finalizeFromPersistedRaw(string $requestId): void
    {
        $raw = DB::table('request_raw')->where('request_id', $requestId)->first();
        if (! $raw || $raw->response_payload === null) {
            throw new RuntimeException(
                "finalizeFromPersistedRaw called without persisted success record for {$requestId}",
            );
        }

        $request = DB::table('requests')->where('request_id', $requestId)->first();
        if (! $request) {
            throw new RuntimeException("requests row missing for {$requestId}");
        }

        Log::channel('llm')->info('async.idempotent_finalize', [
            'request_id' => $requestId,
            'prior_status' => $request->status,
        ]);

        $decoded = json_decode($raw->response_payload, true, 512, JSON_THROW_ON_ERROR);
        $usage = is_array($decoded) && isset($decoded['usage']) && is_array($decoded['usage'])
            ? $decoded['usage']
            : [];
        $httpStatus = 200;

        DB::transaction(function () use ($requestId, $request, $usage, $httpStatus): void {
            DB::table('requests')
                ->where('request_id', $requestId)
                ->update([
                    'status' => RequestStatus::Completed->value,
                    'http_status' => $request->http_status ?? $httpStatus,
                    'anthropic_request_id' => $request->anthropic_request_id,
                    'completed_at' => $request->completed_at ?? now(),
                ]);

            try {
                DB::table('request_usage')->insert([
                    'request_id' => $requestId,
                    'input_tokens' => $usage['input_tokens'] ?? 0,
                    'output_tokens' => $usage['output_tokens'] ?? 0,
                    'cache_creation_5m_tokens' => $usage['cache_creation_5m_input_tokens'] ?? 0,
                    'cache_creation_1h_tokens' => $usage['cache_creation_1h_input_tokens'] ?? 0,
                    'cache_read_tokens' => $usage['cache_read_input_tokens'] ?? 0,
                    'thinking_tokens' => $usage['thinking_tokens'] ?? 0,
                    'server_tool_web_search_count' => $usage['server_tool_web_search_count'] ?? 0,
                    'server_tool_web_fetch_count' => $usage['server_tool_web_fetch_count'] ?? 0,
                    'server_tool_code_exec_count' => $usage['server_tool_code_exec_count'] ?? 0,
                    'server_tool_tool_search_count' => $usage['server_tool_tool_search_count'] ?? 0,
                    'cost_usd' => '0.00000000',
                    'cost_breakdown' => json_encode([]),
                ]);
            } catch (QueryException $e) {
                if (! $this->isDuplicateKeyException($e)) {
                    throw $e;
                }
            }
        });
    }

    private function persistSuccessRawRecord(
        string $requestId,
        string $maskedRequestPayload,
        SendMessageOutput $output,
        DateTimeImmutable $retentionUntil,
    ): void {
        $responsePayload = $output->parsedResponse !== null
            ? PayloadMasker::mask(
                json_encode($output->parsedResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            )
            : PayloadMasker::mask($output->envelope->rawBody);

        try {
            DB::transaction(function () use ($requestId, $maskedRequestPayload, $responsePayload, $retentionUntil): void {
                DB::table('request_raw')->insert([
                    'request_id' => $requestId,
                    'request_payload' => $maskedRequestPayload,
                    'response_payload' => $responsePayload,
                    'retention_until' => $retentionUntil->format('Y-m-d H:i:s'),
                ]);
            });
        } catch (QueryException $e) {
            if (! $this->isDuplicateKeyException($e)) {
                throw $e;
            }
        }
    }

    private function insertFailureRawRecord(
        string $requestId,
        string $maskedRequestPayload,
        DateTimeImmutable $retentionUntil,
    ): void {
        try {
            DB::table('request_raw')->insert([
                'request_id' => $requestId,
                'request_payload' => $maskedRequestPayload,
                'response_payload' => null,
                'retention_until' => $retentionUntil->format('Y-m-d H:i:s'),
            ]);
        } catch (QueryException $e) {
            if (! $this->isDuplicateKeyException($e)) {
                throw $e;
            }
        }
    }

    private function updateRequestRow(string $requestId, SendMessageOutput $output): void
    {
        $status = $output->isSuccess
            ? RequestStatus::Completed
            : ($output->envelope->httpStatusCode >= 500
                ? RequestStatus::FailedServerError
                : RequestStatus::FailedClientError);

        DB::table('requests')
            ->where('request_id', $requestId)
            ->update([
                'status' => $status->value,
                'http_status' => $output->envelope->httpStatusCode,
                'anthropic_request_id' => $output->anthropicRequestId,
                'error_type' => $output->errorType,
                'error_message' => $output->errorMessage,
                'service_tier_used' => $output->serviceTierUsed,
                'completed_at' => now(),
            ]);
    }

    private function insertUsageRow(string $requestId, SendMessageOutput $output): void
    {
        try {
            DB::table('request_usage')->insert([
                'request_id' => $requestId,
                'input_tokens' => $output->usage['input_tokens'] ?? 0,
                'output_tokens' => $output->usage['output_tokens'] ?? 0,
                'cache_creation_5m_tokens' => $output->usage['cache_creation_5m_input_tokens'] ?? 0,
                'cache_creation_1h_tokens' => $output->usage['cache_creation_1h_input_tokens'] ?? 0,
                'cache_read_tokens' => $output->usage['cache_read_input_tokens'] ?? 0,
                'thinking_tokens' => $output->usage['thinking_tokens'] ?? 0,
                'server_tool_web_search_count' => $output->usage['server_tool_web_search_count'] ?? 0,
                'server_tool_web_fetch_count' => $output->usage['server_tool_web_fetch_count'] ?? 0,
                'server_tool_code_exec_count' => $output->usage['server_tool_code_exec_count'] ?? 0,
                'server_tool_tool_search_count' => $output->usage['server_tool_tool_search_count'] ?? 0,
                'cost_usd' => number_format($output->costUsd, 8, '.', ''),
                'cost_breakdown' => json_encode($output->costBreakdown),
            ]);
        } catch (QueryException $e) {
            if (! $this->isDuplicateKeyException($e)) {
                throw $e;
            }
        }
    }

    private function isDuplicateKeyException(QueryException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }
}
