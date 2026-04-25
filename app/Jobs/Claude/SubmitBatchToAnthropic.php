<?php

declare(strict_types=1);

namespace App\Jobs\Claude;

use App\Components\Claude\Batch\BatchPayloadBuilder;
use App\Components\Claude\Enums\BatchItemStatus;
use App\Components\Claude\Enums\BatchStatus;
use App\Components\RateLimiting\Claude\ClaudeRateLimitTracker;
use App\Components\RateLimiting\Claude\RateLimitNamespace;
use App\Components\Routing\WorkspaceResolver;
use App\Models\BatchRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class SubmitBatchToAnthropic implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    private const int MAX_SUBMIT_ATTEMPTS = 3;

    public function __construct(public readonly string $batchId) {}

    public function handle(
        BatchPayloadBuilder $payloadBuilder,
        WorkspaceResolver $workspaces,
        ClaudeRateLimitTracker $rateLimitTracker,
    ): void {
        $batch = BatchRecord::where('batch_id', $this->batchId)->firstOrFail();
        $batch->load('items');

        $batch->update([
            'status' => BatchStatus::Submitting,
            'submitted_at' => now(),
        ]);

        $client = $batch->client;
        if ($client === null) {
            throw new \RuntimeException("Client not found for batch {$batch->batch_id} (client_id={$batch->client_id})");
        }
        $workspace = $workspaces->resolveForClient($client);
        $payload = $payloadBuilder->build($batch, $client);

        $response = Http::withHeaders([
            'x-api-key' => $workspace->apiKey,
            'anthropic-version' => config('llm.claude.anthropic_version'),
            'content-type' => 'application/json',
        ])
            ->timeout(config('llm.claude.timeouts.request'))
            ->connectTimeout(config('llm.claude.timeouts.connect'))
            ->retry(0)
            ->post(config('llm.claude.endpoints.batches'), $payload);

        $statusCode = $response->status();

        if ($statusCode >= 200 && $statusCode < 300) {
            $this->handleSuccess($batch, $response, $rateLimitTracker, $workspace->apiKey);

            return;
        }

        if ($statusCode === 429) {
            $this->handleRateLimit($batch, $response);

            return;
        }

        if ($statusCode === 401 || $statusCode === 403) {
            $this->handleAuthFailure($batch, $response);

            return;
        }

        if ($statusCode >= 400 && $statusCode < 500) {
            $this->handleClientError($batch, $response);

            return;
        }

        $this->handleServerError($batch, $response);
    }

    private function handleSuccess(
        BatchRecord $batch,
        HttpResponse $response,
        ClaudeRateLimitTracker $rateLimitTracker,
        string $apiKey,
    ): void {
        $body = $response->json();

        $batch->update([
            'anthropic_batch_id' => $body['id'] ?? null,
            'status' => BatchStatus::InProgress,
        ]);

        $headers = $this->filterRateLimitHeaders($response->headers());
        $rateLimitTracker->recordFromHeaders(RateLimitNamespace::BatchCreate, md5($apiKey), '', $headers);
    }

    private function handleRateLimit(BatchRecord $batch, HttpResponse $response): void
    {
        $retryAfter = (int) ($response->header('retry-after') ?: 60);

        $batch->increment('submit_attempts');

        $this->release($retryAfter);

        Log::channel('llm')->warning('Batch submit rate-limited', [
            'batch_id' => $batch->batch_id,
            'retry_after' => $retryAfter,
        ]);
    }

    private function handleAuthFailure(BatchRecord $batch, HttpResponse $response): void
    {
        $batch->update(['status' => BatchStatus::Failed]);

        $this->markAllItemsErrored($batch, 'workspace_auth_failed', $response->body());

        Log::channel('llm')->error('Batch submit auth failure', [
            'batch_id' => $batch->batch_id,
            'status' => $response->status(),
        ]);
    }

    private function handleClientError(BatchRecord $batch, HttpResponse $response): void
    {
        $batch->update(['status' => BatchStatus::Failed]);

        $this->markAllItemsErrored($batch, 'anthropic_rejected_batch', $response->body());

        $this->dispatchErrorCallback($batch, $response->body());
    }

    private function handleServerError(BatchRecord $batch, HttpResponse $response): void
    {
        $newAttempts = $batch->submit_attempts + 1;

        if ($newAttempts >= self::MAX_SUBMIT_ATTEMPTS) {
            $batch->update([
                'status' => BatchStatus::Failed,
                'submit_attempts' => $newAttempts,
            ]);

            $this->markAllItemsErrored($batch, 'anthropic_server_error', $response->body());
            $this->dispatchErrorCallback($batch, $response->body());

            return;
        }

        $batch->update(['submit_attempts' => $newAttempts]);

        $this->release(120);

        Log::channel('llm')->warning('Batch submit server error, retrying', [
            'batch_id' => $batch->batch_id,
            'attempt' => $newAttempts,
            'status' => $response->status(),
        ]);
    }

    private function markAllItemsErrored(BatchRecord $batch, string $errorType, string $errorMessage): void
    {
        $batch->items()->update([
            'status' => BatchItemStatus::Errored->value,
            'error_type' => $errorType,
            'error_message' => mb_substr($errorMessage, 0, 2000),
        ]);
    }

    private function dispatchErrorCallback(BatchRecord $batch, string $errorBody): void
    {
        if ($batch->callback_url === null) {
            return;
        }

        Log::channel('llm')->info('Batch error callback would be dispatched', [
            'batch_id' => $batch->batch_id,
            'callback_url' => $batch->callback_url,
        ]);
    }

    /**
     * @param  array<string, string|list<string>>  $responseHeaders
     * @return array<string, string>
     */
    private function filterRateLimitHeaders(array $responseHeaders): array
    {
        $filtered = [];

        foreach ($responseHeaders as $name => $values) {
            $lower = strtolower((string) $name);
            if (str_starts_with($lower, 'anthropic-ratelimit-')) {
                $filtered[$lower] = is_array($values) ? $values[0] : (string) $values;
            }
        }

        return $filtered;
    }
}
