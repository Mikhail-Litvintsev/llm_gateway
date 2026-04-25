<?php

declare(strict_types=1);

namespace App\Components\Claude;

use App\Components\Billing\Billing;
use App\Components\Claude\Batch\BatchPersister;
use App\Components\Claude\Batch\BatchPreValidator;
use App\Components\Claude\Batch\BatchResultParser;
use App\Components\Claude\Contracts\MessageSender;
use App\Components\Claude\DTO\Batch;
use App\Components\Claude\DTO\BatchCreateRequest;
use App\Components\Claude\DTO\ClaudeFile;
use App\Components\Claude\DTO\SendMessageInput;
use App\Components\Claude\DTO\SendMessageOutput;
use App\Components\Claude\Enums\BatchStatus;
use App\Components\Claude\Errors\ErrorMapper;
use App\Components\Claude\Exceptions\FileNotFoundException;
use App\Components\Claude\Files\DTO\FileListPage;
use App\Components\Claude\Files\FilePurpose;
use App\Components\Claude\Files\FilesDeletionHandler;
use App\Components\Claude\Files\FilesRepository;
use App\Components\Claude\Files\FilesUploadHandler;
use App\Components\Claude\Payload\DTO\BuiltPayload;
use App\Components\Claude\Response\ResponseParser;
use App\Components\Delivery\Stream\DTO\StreamContext;
use App\Components\Delivery\Stream\DTO\StreamOutcome;
use App\Components\Delivery\Stream\StreamResponder;
use App\Components\Delivery\Sync\DTO\AnthropicResponseEnvelope;
use App\Components\Logging\DTO\LoggingRecord;
use App\Components\Logging\Enums\Endpoint;
use App\Components\Logging\Enums\Mode;
use App\Components\Logging\Enums\RequestStatus;
use App\Components\Logging\Logging;
use App\Components\Pricing\CostCalculator;
use App\Components\Pricing\DTO\CostBreakdown;
use App\Components\RateLimiting\Claude\ClaudeRateLimitTracker;
use App\Components\RateLimiting\Claude\Exceptions\RateLimitExceededException;
use App\Components\RateLimiting\Claude\RateLimitNamespace;
use App\Components\Routing\WorkspaceResolver;
use App\Jobs\Claude\SubmitBatchToAnthropic;
use App\Models\Client;
use App\Models\FileRecord;
use DateTimeImmutable;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final readonly class Claude implements MessageSender
{
    public function __construct(
        private WorkspaceResolver $workspaces,
        private ResponseParser $responseParser,
        private ErrorMapper $errorMapper,
        private CostCalculator $costCalculator,
        private ClaudeRateLimitTracker $rateLimitTracker,
        private StreamResponder $streamResponder,
        private Billing $billing,
        private Logging $logging,
        private BatchPreValidator $batchPreValidator,
        private BatchPersister $batchPersister,
        private FilesUploadHandler $filesUploadHandler,
        private FilesDeletionHandler $filesDeletionHandler,
        private FilesRepository $filesRepository,
        private EndpointRegistry $endpoints,
    ) {}

    /**
     * @throws ConnectionException
     * @throws RateLimitExceededException
     * @throws \JsonException
     */
    public function sendMessage(SendMessageInput $input): SendMessageOutput
    {
        $workspace = $this->workspaces->resolveForClient($input->client);

        $this->rateLimitTracker->canProceed(
            RateLimitNamespace::Messages,
            md5($workspace->apiKey),
            $input->payload->modelSnapshot,
            estimatedInputTokens: $input->estimatedInputTokens,
            estimatedOutputTokens: $input->estimatedOutputTokens,
            expectedCacheReadTokens: $input->expectedCacheReadTokens,
        );

        $startMs = (int) (microtime(true) * 1000);

        $response = Http::withHeaders($this->buildHeaders($workspace->apiKey, $input->payload->betaHeaders))
            ->withBody($input->payload->jsonBody, 'application/json')
            ->timeout(config('llm.claude.timeouts.request'))
            ->connectTimeout(config('llm.claude.timeouts.connect'))
            ->retry(
                times: (int) config('llm.claude.http_retry.max_attempts'),
                sleepMilliseconds: function (int $attempt, ?Throwable $exception): int {
                    if ($exception instanceof RequestException && $exception->response !== null) {
                        $retryAfter = (int) $exception->response->header('retry-after');
                        if ($retryAfter > 0) {
                            return $retryAfter * 1000;
                        }
                    }

                    $baseDelayMs = (int) config('llm.claude.http_retry.base_delay_ms');

                    return $baseDelayMs * (2 ** ($attempt - 1));
                },
                when: function (Throwable $exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }
                    if ($exception instanceof RequestException && $exception->response !== null) {
                        /** @var int[] $retryableStatuses */
                        $retryableStatuses = (array) config('llm.claude.http_retry.retryable_statuses');

                        return in_array($exception->response->status(), $retryableStatuses, true);
                    }

                    return false;
                },
                throw: false,
            )
            ->post($this->endpoints->messages());

        $latencyMs = ((int) (microtime(true) * 1000)) - $startMs;

        $statusCode = $response->status();
        $rawBody = $response->body();
        $headers = $this->filterAnthropicHeaders($response->headers());

        $this->rateLimitTracker->recordFromHeaders(
            RateLimitNamespace::Messages,
            md5($workspace->apiKey),
            $input->payload->modelSnapshot,
            $headers,
        );

        if ($statusCode >= 200 && $statusCode < 300) {
            return $this->handleSuccess($rawBody, $headers, $statusCode, $latencyMs, $input);
        }

        return $this->handleError($rawBody, $headers, $statusCode, $latencyMs, $input);
    }

    /**
     * Streams an Anthropic Messages API call via SSE pass-through.
     * Billing and logging happen in the $onComplete callback after the stream ends.
     *
     * @throws RateLimitExceededException
     */
    /**
     * @param  list<string>  $features
     */
    public function streamMessage(
        SendMessageInput $input,
        Client $client,
        string $gatewayRequestId,
        string $modelAlias,
        string $modelSnapshot,
        array $features,
    ): StreamedResponse {
        $workspace = $this->workspaces->resolveForClient($client);

        $this->rateLimitTracker->canProceed(
            RateLimitNamespace::Messages,
            md5($workspace->apiKey),
            $modelSnapshot,
            estimatedInputTokens: $input->estimatedInputTokens,
            estimatedOutputTokens: $input->estimatedOutputTokens,
            expectedCacheReadTokens: $input->expectedCacheReadTokens,
        );

        $onComplete = function (StreamOutcome $outcome) use ($input, $client, $gatewayRequestId, $modelAlias, $modelSnapshot): void {
            if ($outcome->httpStatusCode === 200 && $outcome->completed) {
                $this->billing->recordSpend($client, $outcome->costUsd);
            }

            $status = match (true) {
                $outcome->httpStatusCode !== 200 => RequestStatus::FailedClientError,
                $outcome->clientDisconnected && $outcome->completed => RequestStatus::CompletedDisconnected,
                $outcome->completed => RequestStatus::Completed,
                default => RequestStatus::FailedServerError,
            };

            $now = new DateTimeImmutable;

            $this->logging->record(new LoggingRecord(
                requestId: $gatewayRequestId,
                clientId: $client->id,
                endpoint: Endpoint::Messages,
                mode: Mode::SyncStream,
                modelAlias: $modelAlias,
                modelSnapshot: $modelSnapshot,
                anthropicRequestId: $outcome->anthropicHeaders['request-id'] ?? null,
                anthropicOrganizationId: $outcome->anthropicHeaders['anthropic-organization-id'] ?? null,
                status: $status,
                httpStatus: $outcome->httpStatusCode ?? 500,
                errorType: $outcome->errorType,
                errorMessage: null,
                serviceTierUsed: $outcome->aggregate->serviceTier,
                createdAt: $now,
                startedAt: $now,
                completedAt: $now,
                inputTokens: $outcome->aggregate->inputTokens ?? 0,
                outputTokens: $outcome->aggregate->outputTokens ?? 0,
                cacheReadTokens: $outcome->aggregate->cacheReadInputTokens ?? 0,
                thinkingTokens: $outcome->aggregate->thinkingTokens ?? 0,
                costUsd: number_format($outcome->costUsd, 8, '.', ''),
                costBreakdown: $outcome->costBreakdown,
                requestPayload: $input->payload->jsonBody,
                retentionUntil: new DateTimeImmutable('+'.config('llm.raw_log_retention_days', 14).' days'),
            ));
        };

        return $this->streamResponder->stream(new StreamContext(
            payload: $input->payload,
            client: $client,
            gatewayRequestId: $gatewayRequestId,
            featuresUsed: $features,
            onComplete: $onComplete,
        ));
    }

    /**
     * Calls Anthropic's /v1/messages/count_tokens endpoint.
     * count_tokens invocations are intentionally not persisted to `requests`;
     * add logging in a later phase if observability demand exceeds cost.
     * No billing, no spend mutation.
     *
     * @throws RateLimitExceededException
     */
    public function countTokens(BuiltPayload $payload, Client $client): AnthropicResponseEnvelope
    {
        $workspace = $this->workspaces->resolveForClient($client);

        $this->rateLimitTracker->canProceed(
            RateLimitNamespace::Messages,
            md5($workspace->apiKey),
            $payload->modelSnapshot,
            estimatedInputTokens: 0,
            estimatedOutputTokens: 0,
            expectedCacheReadTokens: 0,
        );

        $response = Http::withHeaders($this->buildHeaders($workspace->apiKey, $payload->betaHeaders))
            ->withBody($payload->jsonBody, 'application/json')
            ->timeout(config('llm.claude.timeouts.request'))
            ->connectTimeout(config('llm.claude.timeouts.connect'))
            ->post($this->endpoints->countTokens());

        $headers = $this->filterAnthropicHeaders($response->headers());

        $this->rateLimitTracker->recordFromHeaders(
            RateLimitNamespace::Messages,
            md5($workspace->apiKey),
            $payload->modelSnapshot,
            $headers,
        );

        return new AnthropicResponseEnvelope(
            httpStatusCode: $response->status(),
            rawBody: $response->body(),
            anthropicHeaders: $headers,
        );
    }

    /**
     * @throws Throwable
     */
    public function createBatch(BatchCreateRequest $request, int $clientId): Batch
    {
        $client = Client::findOrFail($clientId);
        $this->batchPreValidator->validate($request, $client);
        $batchRecord = $this->batchPersister->persist($request, $client);

        if ($request->submitImmediately) {
            SubmitBatchToAnthropic::dispatch($batchRecord->batch_id)
                ->onQueue(config('llm.queues.batch'));
        }

        return Batch::fromRecord($batchRecord);
    }

    public function getBatch(string $anthropicBatchId, Client $client): Batch
    {
        $workspace = $this->workspaces->resolveForClient($client);

        $response = Http::withHeaders($this->buildHeaders($workspace->apiKey))
            ->timeout(config('llm.claude.timeouts.request'))
            ->connectTimeout(config('llm.claude.timeouts.connect'))
            ->get($this->endpoints->batch($anthropicBatchId));

        if (! $response->successful()) {
            throw new LogicException("Failed to get batch: HTTP {$response->status()}");
        }

        $body = $response->json();

        return new Batch(
            batchId: '',
            status: BatchStatus::InProgress,
            requestCount: ($body['request_counts']['processing'] ?? 0)
                + ($body['request_counts']['succeeded'] ?? 0)
                + ($body['request_counts']['errored'] ?? 0)
                + ($body['request_counts']['canceled'] ?? 0)
                + ($body['request_counts']['expired'] ?? 0),
            anthropicBatchId: $body['id'] ?? $anthropicBatchId,
            createdAt: $body['created_at'] ?? now()->toIso8601String(),
            succeededCount: $body['request_counts']['succeeded'] ?? 0,
            erroredCount: $body['request_counts']['errored'] ?? 0,
            cancelledCount: $body['request_counts']['canceled'] ?? 0,
            expiredCount: $body['request_counts']['expired'] ?? 0,
        );
    }

    /**
     * @return Generator<DTO\ResultLine>
     */
    public function getBatchResults(string $anthropicBatchId, Client $client): Generator
    {
        $workspace = $this->workspaces->resolveForClient($client);

        $response = Http::withHeaders($this->buildHeaders(
            $workspace->apiKey,
            extraHeaders: ['accept' => 'application/x-ndjson'],
        ))
            ->withOptions(['stream' => true])
            ->timeout(config('llm.claude.timeouts.request'))
            ->connectTimeout(config('llm.claude.timeouts.connect'))
            ->get($this->endpoints->batchResults($anthropicBatchId));

        $parser = new BatchResultParser;
        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $chunk = $body->read(8192);

            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                $result = $parser->parseLine($line);

                if ($result !== null) {
                    yield $result;
                }
            }
        }

        if ($buffer !== '') {
            $result = $parser->parseLine($buffer);

            if ($result !== null) {
                yield $result;
            }
        }
    }

    public function uploadFile(Client $client, UploadedFile $file, FilePurpose $purpose): ClaudeFile
    {
        return $this->filesUploadHandler->upload($client, $file, $purpose);
    }

    public function deleteFile(string $fileId, string $clientId): void
    {
        $record = $this->filesRepository->findForClient($fileId, $clientId);

        if ($record === null) {
            throw new FileNotFoundException($fileId);
        }

        $client = $record->client;
        $this->filesDeletionHandler->delete($record, $client);
    }

    public function getFile(string $fileId, string $clientId): ClaudeFile
    {
        $record = $this->filesRepository->findForClient($fileId, $clientId);

        if ($record === null) {
            throw new FileNotFoundException($fileId);
        }

        return ClaudeFile::fromRecord($record);
    }

    public function listFiles(string $clientId, ?string $cursor, int $limit, ?FilePurpose $purpose): FileListPage
    {
        $cursorCreatedAt = null;
        $cursorId = null;

        if ($cursor !== null) {
            $decoded = json_decode(base64_decode($cursor, true) ?: '', true);

            if (is_array($decoded) && isset($decoded['created_at'], $decoded['id'])) {
                $cursorCreatedAt = $decoded['created_at'];
                $cursorId = (int) $decoded['id'];
            }
        }

        $records = $this->filesRepository->listForClient($clientId, $limit + 1, $cursorCreatedAt, $cursorId, $purpose);

        $hasMore = $records->count() > $limit;
        $records = $records->take($limit);

        $nextCursor = null;

        if ($hasMore && $records->isNotEmpty()) {
            $last = $records->last();
            $nextCursor = base64_encode(json_encode([
                'created_at' => $last->created_at->toIso8601String(),
                'id' => $last->id,
            ]));
        }

        $files = $records->map(fn (FileRecord $r) => ClaudeFile::fromRecord($r))->values()->all();

        return new FileListPage($files, $nextCursor);
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    private function handleSuccess(
        string $rawBody,
        array $headers,
        int $statusCode,
        int $latencyMs,
        SendMessageInput $input,
    ): SendMessageOutput {
        $result = $this->responseParser->parseSuccess($rawBody);
        $usage = $result['usage'];
        $usageData = $this->responseParser->extractUsageData($usage);

        $costBreakdown = $this->costCalculator->calculate(
            $usageData,
            $input->payload->modelAlias,
            false,
            $input->client->inference_geo === 'us',
        );

        return new SendMessageOutput(
            envelope: new AnthropicResponseEnvelope($statusCode, $rawBody, $headers),
            parsedResponse: $result['parsed'],
            usage: $usage,
            costUsd: $costBreakdown->totalCost->toFloat(),
            costBreakdown: $this->buildCostBreakdownArray($costBreakdown),
            serviceTierUsed: $usage['service_tier'] ?? null,
            cacheHitTokens: ($usage['cache_read_input_tokens'] ?? 0) > 0
                ? $usage['cache_read_input_tokens']
                : null,
            anthropicRequestId: $headers['request-id'] ?? null,
            latencyMs: $latencyMs,
            isSuccess: true,
        );
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    private function handleError(
        string $rawBody,
        array $headers,
        int $statusCode,
        int $latencyMs,
        SendMessageInput $input,
    ): SendMessageOutput {
        $error = $this->errorMapper->map($statusCode, $rawBody);

        return new SendMessageOutput(
            envelope: new AnthropicResponseEnvelope($statusCode, $rawBody, $headers),
            parsedResponse: null,
            usage: null,
            costUsd: 0.0,
            costBreakdown: [],
            serviceTierUsed: null,
            cacheHitTokens: null,
            anthropicRequestId: $headers['request-id'] ?? null,
            latencyMs: $latencyMs,
            isSuccess: false,
            errorType: $error['type'],
            errorMessage: $error['message'],
        );
    }

    /**
     * @param  string[]  $betaHeaders
     * @param  array<string, string>  $extraHeaders
     * @return array<string, string>
     */
    private function buildHeaders(string $apiKey, array $betaHeaders = [], array $extraHeaders = []): array
    {
        $headers = [
            'x-api-key' => $apiKey,
            'anthropic-version' => (string) config('llm.claude.anthropic_version'),
            'content-type' => 'application/json',
        ];

        $beta = implode(',', $betaHeaders);
        if ($beta !== '') {
            $headers['anthropic-beta'] = $beta;
        }

        return array_merge($headers, $extraHeaders);
    }

    /**
     * @param  array<string, string|list<string>>  $responseHeaders
     * @return array<string, string>
     */
    private function filterAnthropicHeaders(array $responseHeaders): array
    {
        $allowed = ['request-id', 'anthropic-organization-id'];
        $filtered = [];

        foreach ($responseHeaders as $name => $values) {
            $lower = strtolower((string) $name);
            if (in_array($lower, $allowed, true) || str_starts_with($lower, 'anthropic-ratelimit-')) {
                $filtered[$lower] = is_array($values) ? $values[0] : (string) $values;
            }
        }

        return $filtered;
    }

    /**
     * @return array<string, float>
     */
    private function buildCostBreakdownArray(CostBreakdown $breakdown): array
    {
        return [
            'input' => $breakdown->inputCost->toFloat(),
            'output' => $breakdown->outputCost->toFloat(),
            'cache_write_5m' => $breakdown->cacheWrite5mCost->toFloat(),
            'cache_write_1h' => $breakdown->cacheWrite1hCost->toFloat(),
            'cache_read' => $breakdown->cacheReadCost->toFloat(),
            'server_tool_web_search' => $breakdown->serverToolWebSearchCost->toFloat(),
            'server_tool_code_exec' => $breakdown->serverToolCodeExecCost->toFloat(),
        ];
    }
}
