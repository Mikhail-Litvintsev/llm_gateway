<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Components\Billing\Billing;
use App\Components\Billing\CostEstimator;
use App\Components\Caching\Caching;
use App\Components\Claude\Contracts\MessageSender;
use App\Components\Claude\DTO\SendMessageInput;
use App\Components\Claude\Payload\FeatureDetector;
use App\Components\Claude\Payload\PayloadBuilder;
use App\Components\Logging\Enums\RequestStatus;
use App\Components\Logging\Logging;
use App\Components\RateLimiting\Claude\Exceptions\RateLimitExceededException;
use App\Models\AsyncPending;
use App\Models\Client;
use App\Repositories\AsyncPendingRepository;
use App\Repositories\RequestRepository;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessAsyncMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public readonly string $requestId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping($this->requestId)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10)->toDateTimeImmutable();
    }

    public function handle(
        MessageSender $claude,
        PayloadBuilder $payloadBuilder,
        Billing $billing,
        Logging $logging,
        Caching $caching,
        CostEstimator $costEstimator,
        FeatureDetector $featureDetector,
        RequestRepository $requests,
        AsyncPendingRepository $pending,
    ): void {
        $pendingRow = $pending->find($this->requestId);
        if ($pendingRow === null) {
            return;
        }

        $requestRow = $requests->find($this->requestId);
        if ($requestRow === null) {
            return;
        }

        if ($this->handleIdempotentFinalize($pendingRow, $logging, $pending)) {
            return;
        }

        if ($requestRow->status === RequestStatus::Completed->value) {
            return;
        }

        $client = Client::find($requestRow->client_id);
        if ($client === null) {
            return;
        }

        $this->executeClaudeAndFinalize(
            $client, $claude, $payloadBuilder, $billing, $logging, $caching,
            $costEstimator, $featureDetector, $pendingRow, $requests, $pending,
        );
    }

    public function failed(Throwable $exception): void
    {
        $logging = app(Logging::class);
        $requests = app(RequestRepository::class);
        $pending = app(AsyncPendingRepository::class);

        $this->logFailure($exception);

        if ($this->tryIdempotentFinalizeAfterFailure($logging, $pending)) {
            return;
        }

        $this->transitionToFailedIfNotFinalized($exception, $requests);

        DeliverWebhook::dispatch($this->requestId)->onQueue('default');
    }

    private function handleIdempotentFinalize(
        AsyncPending $pendingRow,
        Logging $logging,
        AsyncPendingRepository $pending,
    ): bool {
        if (! $this->hasPersistedSuccessResponse()) {
            return false;
        }

        if (in_array($pendingRow->status, ['delivered', 'exhausted'], true)) {
            return true;
        }

        $logging->finalizeFromPersistedRaw($this->requestId);
        $pending->markProcessing($this->requestId);
        DeliverWebhook::dispatch($this->requestId)->onQueue('default');

        return true;
    }

    private function executeClaudeAndFinalize(
        Client $client,
        MessageSender $claude,
        PayloadBuilder $payloadBuilder,
        Billing $billing,
        Logging $logging,
        Caching $caching,
        CostEstimator $costEstimator,
        FeatureDetector $featureDetector,
        AsyncPending $pendingRow,
        RequestRepository $requests,
        AsyncPendingRepository $pending,
    ): void {
        $payload = json_decode($pendingRow->payload_for_anthropic, true, 512, JSON_THROW_ON_ERROR);
        $modelAlias = $payload['model'] ?? $client->default_model_alias ?? config('llm.claude.default_model_alias');

        $this->markInProgress($requests, $pending);

        $injected = $caching->autoInject($payload, $modelAlias, $client)->payload;
        $builtPayload = $payloadBuilder->build($injected, $client);
        $features = [...$featureDetector->detect($injected), 'webhook'];
        $tokenEstimate = $costEstimator->estimateTokens($injected, $modelAlias);

        try {
            $output = $claude->sendMessage(new SendMessageInput(
                payload: $builtPayload,
                client: $client,
                gatewayRequestId: $this->requestId,
                featuresUsed: $features,
                estimatedInputTokens: $tokenEstimate->inputTokens,
                estimatedOutputTokens: $tokenEstimate->outputTokens,
                expectedCacheReadTokens: $tokenEstimate->cacheReadTokens,
            ));
        } catch (RateLimitExceededException $e) {
            $this->logRateLimitRelease($e);
            $this->release($e->retryAfterSeconds);

            return;
        }

        $logging->updateAsyncRecord($this->requestId, $output, $builtPayload->decodedPayload, $features);

        if ($output->isSuccess) {
            $billing->recordSpend($client, $output->costUsd);
        }

        $pending->markProcessing($this->requestId);
        DeliverWebhook::dispatch($this->requestId)->onQueue('default');
    }

    private function markInProgress(RequestRepository $requests, AsyncPendingRepository $pending): void
    {
        $pending->markProcessing($this->requestId);
        $requests->markInProgress($this->requestId, RequestStatus::InProgress->value);
    }

    private function logRateLimitRelease(RateLimitExceededException $e): void
    {
        Log::channel('llm')->info('ProcessAsyncMessage::rateLimit::release', [
            'request_id' => $this->requestId,
            'axis' => $e->axis,
            'retry_after_seconds' => $e->retryAfterSeconds,
            'attempt' => $this->attempts(),
        ]);
    }

    private function logFailure(Throwable $exception): void
    {
        Log::channel('llm')->error('ProcessAsyncMessage::failed', [
            'request_id' => $this->requestId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    private function tryIdempotentFinalizeAfterFailure(Logging $logging, AsyncPendingRepository $pending): bool
    {
        if (! $this->hasPersistedSuccessResponse()) {
            return false;
        }

        $asyncStatus = $pending->getStatus($this->requestId);
        if (in_array($asyncStatus, ['delivered', 'exhausted'], true)) {
            return true;
        }

        $logging->finalizeFromPersistedRaw($this->requestId);
        $pending->markProcessing($this->requestId);
        DeliverWebhook::dispatch($this->requestId)->onQueue('default');

        return true;
    }

    private function transitionToFailedIfNotFinalized(Throwable $exception, RequestRepository $requests): void
    {
        $currentStatus = $requests->getStatus($this->requestId);

        $isFinalized = in_array($currentStatus, [
            RequestStatus::Completed->value,
            RequestStatus::CompletedDisconnected->value,
            RequestStatus::FailedClientError->value,
            RequestStatus::FailedServerError->value,
            RequestStatus::FailedCallbackDelivery->value,
        ], true);

        if ($isFinalized) {
            return;
        }

        $requests->markFinalStatus(
            $this->requestId,
            RequestStatus::FailedServerError->value,
            'async_job_failed',
            $exception->getMessage(),
        );
    }

    private function hasPersistedSuccessResponse(): bool
    {
        return DB::table('request_raw')
            ->where('request_id', $this->requestId)
            ->whereNotNull('response_payload')
            ->exists();
    }
}
