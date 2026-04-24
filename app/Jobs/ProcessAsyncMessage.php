<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Components\Authorization\Authorization;
use App\Components\Billing\Billing;
use App\Components\Billing\CostEstimator;
use App\Components\Caching\Caching;
use App\Components\Claude\Contracts\MessageSender;
use App\Components\Claude\DTO\SendMessageInput;
use App\Components\Claude\Payload\PayloadBuilder;
use App\Components\Logging\Enums\RequestStatus;
use App\Components\Logging\Logging;
use App\Components\RateLimiting\Claude\Exceptions\RateLimitExceededException;
use App\Models\Client;
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
        Authorization $authorization,
        Billing $billing,
        Logging $logging,
        Caching $caching,
        CostEstimator $costEstimator,
    ): void {
        $pending = DB::table('async_pending')->where('request_id', $this->requestId)->first();
        if (! $pending) {
            return;
        }

        $requestRow = DB::table('requests')->where('request_id', $this->requestId)->first();
        if (! $requestRow) {
            return;
        }

        if ($this->hasPersistedSuccessResponse()) {
            if (in_array($pending->status, ['delivered', 'exhausted'], true)) {
                return;
            }

            $logging->finalizeFromPersistedRaw($this->requestId);
            DB::table('async_pending')->where('request_id', $this->requestId)->update([
                'status' => 'processing',
                'updated_at' => now(),
            ]);
            DeliverWebhook::dispatch($this->requestId)->onQueue('default');

            return;
        }

        if ($requestRow->status === RequestStatus::Completed->value) {
            return;
        }

        $client = Client::find($requestRow->client_id);
        if (! $client) {
            return;
        }

        $payload = json_decode($pending->payload_for_anthropic, true, 512, JSON_THROW_ON_ERROR);
        $modelAlias = $payload['model'] ?? $client->default_model_alias ?? config('llm.claude.default_model_alias');

        $this->markInProgress();

        $injection = $caching->autoInject($payload, $modelAlias, $client);
        $payload = $injection->payload;
        $builtPayload = $payloadBuilder->build($payload, $client);
        $features = $this->extractFeatures($payload);
        $features[] = 'webhook';
        $tokenEstimate = $costEstimator->estimateTokens($payload, $modelAlias);

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
            Log::channel('llm')->info('ProcessAsyncMessage::rateLimit::release', [
                'request_id' => $this->requestId,
                'axis' => $e->axis,
                'retry_after_seconds' => $e->retryAfterSeconds,
                'attempt' => $this->attempts(),
            ]);

            $this->release($e->retryAfterSeconds);

            return;
        }

        $logging->updateAsyncRecord(
            $this->requestId,
            $output,
            $builtPayload->decodedPayload,
            $features,
        );

        if ($output->isSuccess) {
            $billing->recordSpend($client, $output->costUsd);
        }

        DB::table('async_pending')->where('request_id', $this->requestId)->update([
            'status' => 'processing',
            'updated_at' => now(),
        ]);

        DeliverWebhook::dispatch($this->requestId)->onQueue('default');
    }

    private function hasPersistedSuccessResponse(): bool
    {
        return DB::table('request_raw')
            ->where('request_id', $this->requestId)
            ->whereNotNull('response_payload')
            ->exists();
    }

    public function failed(Throwable $exception, Logging $logging): void
    {
        Log::channel('llm')->error('ProcessAsyncMessage::failed', [
            'request_id' => $this->requestId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);

        if ($this->hasPersistedSuccessResponse()) {
            $asyncStatus = DB::table('async_pending')->where('request_id', $this->requestId)->value('status');
            if (in_array($asyncStatus, ['delivered', 'exhausted'], true)) {
                return;
            }

            $logging->finalizeFromPersistedRaw($this->requestId);

            DB::table('async_pending')->where('request_id', $this->requestId)->update([
                'status' => 'processing',
                'updated_at' => now(),
            ]);
            DeliverWebhook::dispatch($this->requestId)->onQueue('default');

            return;
        }

        $currentStatus = DB::table('requests')->where('request_id', $this->requestId)->value('status');

        $isFinalizedSuccess = in_array($currentStatus, [
            RequestStatus::Completed->value,
            RequestStatus::CompletedDisconnected->value,
        ], true);

        $isFinalizedFailure = in_array($currentStatus, [
            RequestStatus::FailedClientError->value,
            RequestStatus::FailedServerError->value,
            RequestStatus::FailedCallbackDelivery->value,
        ], true);

        if (! $isFinalizedSuccess && ! $isFinalizedFailure) {
            DB::table('requests')
                ->where('request_id', $this->requestId)
                ->update([
                    'status' => RequestStatus::FailedServerError->value,
                    'error_type' => 'async_job_failed',
                    'error_message' => $exception->getMessage(),
                    'completed_at' => now(),
                ]);
        }

        DeliverWebhook::dispatch($this->requestId)->onQueue('default');
    }

    private function markInProgress(): void
    {
        DB::table('async_pending')->where('request_id', $this->requestId)->update([
            'status' => 'processing',
            'updated_at' => now(),
        ]);
        DB::table('requests')->where('request_id', $this->requestId)->update([
            'status' => RequestStatus::InProgress->value,
            'started_at' => now(),
        ]);
    }

    /**
     * @return string[]
     */
    private function extractFeatures(array $payload): array
    {
        $features = [];

        if (isset($payload['thinking'])) {
            $features[] = 'thinking';
        }

        if (isset($payload['tools'])) {
            foreach ($payload['tools'] as $tool) {
                $name = $tool['name'] ?? '';
                if (str_starts_with($name, 'web_search')) {
                    $features[] = 'web_search';
                }
                if ($name === 'code_execution') {
                    $features[] = 'code_execution';
                }
                if (str_starts_with($name, 'computer_')) {
                    $features[] = 'computer_use';
                }
                if ($name === 'bash') {
                    $features[] = 'bash';
                }
                if ($name === 'text_editor') {
                    $features[] = 'text_editor';
                }
            }
        }

        if (($payload['service_tier'] ?? null) === 'auto') {
            $features[] = 'priority_tier';
        }

        if (! empty($payload['citations']['enabled'])) {
            $features[] = 'citations';
        }

        if (isset($payload['output_config'])) {
            $features[] = 'structured_outputs';
        }

        return array_unique($features);
    }
}
