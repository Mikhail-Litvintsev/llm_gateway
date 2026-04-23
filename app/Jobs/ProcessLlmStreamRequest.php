<?php

namespace App\Jobs;

use App\Components\CallbackDelivery\ErrorCallbackBuilder;
use App\Components\CallbackDelivery\StreamingDelivery;
use App\Components\DevMode\DevModeResponseBuilder;
use App\Components\PromptAssembler\PromptAssembler;
use App\Components\ProviderGateway\DTO\UsageInfo;
use App\Components\ProviderGateway\Enums\ProviderName;
use App\Components\ProviderGateway\Exceptions\ProviderInsufficientFundsException;
use App\Components\ProviderGateway\Exceptions\ProviderRateLimitedException;
use App\Components\ProviderGateway\ProviderGateway;
use App\Components\ProviderGateway\Streaming\ProviderStreamReader;
use App\Components\ProviderGateway\Streaming\StreamChunk;
use App\Components\ProviderGateway\Streaming\StreamHandler;
use App\Components\RateLimiter\Claude\ClaudeTokenBudget;
use App\Components\RateLimiter\Claude\ClaudeTokenEstimator;
use App\Components\RateLimiter\RequestThrottle;
use App\Components\RequestPipeline\DTO\ParsedRequest;
use App\Components\RequestPipeline\Enums\RequestStatus;
use App\Components\RequestPipeline\XmlParser;
use App\Models\PendingResponse;
use App\Models\RawResponse;
use App\Models\RequestLog;
use App\Models\ResponseLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessLlmStreamRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;
    public int $maxExceptions = 1;
    public int $timeout = 600;

    private const REQUEUE_DELAY = 60;

    public function __construct(
        public readonly int $requestLogId,
    ) {}

    public function handle(
        XmlParser $xmlParser,
        PromptAssembler $promptAssembler,
        ProviderGateway $providerGateway,
        StreamHandler $streamHandler,
        StreamingDelivery $streamingDelivery,
        ProviderStreamReader $streamReader,
        RequestThrottle $throttle,
        ClaudeTokenEstimator $claudeTokenEstimator,
        ClaudeTokenBudget $claudeTokenBudget,
    ): void {
        $requestLog = RequestLog::findOrFail($this->requestLogId);
        $requestLog->update(['status' => RequestStatus::Processing->value]);

        $startTime = microtime(true);

        // === DEV MODE CHECK ===
        $apiClient = $requestLog->apiClient;
        if ($apiClient && $apiClient->dev_mode) {
            $this->handleDevMode($requestLog, $startTime, $streamingDelivery);
            return;
        }

        try {
            // 1. Load pending prompt
            $pendingPrompt = $requestLog->pendingPrompt;
            if (!$pendingPrompt) {
                throw new \RuntimeException('Pending prompt not found — possibly expired.');
            }

            // 2. Rebuild ParsedRequest from stored XML
            $parsed = $this->rebuildParsedRequest($requestLog, $pendingPrompt, $xmlParser);

            // 3. Resolve provider
            $resolvedProvider = $providerGateway->resolveProvider($parsed);

            // 3.1. Check provider pause (rate limit / insufficient funds)
            if ($throttle->isProviderPaused($resolvedProvider->providerName)) {
                $pauseInfo = $throttle->getProviderPauseInfo($resolvedProvider->providerName);

                Log::channel('llm')->warning('provider.paused_requeue', [
                    'request_id' => $requestLog->request_id,
                    'provider' => $resolvedProvider->providerName,
                    'reason' => $pauseInfo['reason'] ?? 'unknown',
                ]);

                $this->release(self::REQUEUE_DELAY);
                return;
            }

            // 3.2. Check provider rate limit (RPM)
            $providerThrottle = $throttle->attemptProvider($resolvedProvider->providerName);
            if (!$providerThrottle->allowed) {
                Log::channel('llm')->warning('provider.rate_limit_local', [
                    'request_id' => $requestLog->request_id,
                    'provider' => $resolvedProvider->providerName,
                    'retry_after' => $providerThrottle->retryAfter,
                ]);

                $this->release($providerThrottle->retryAfter ?? self::REQUEUE_DELAY);
                return;
            }

            // 4. Assemble payload with stream=true
            $providerRequest = $promptAssembler->assemble($parsed, $resolvedProvider);
            $streamBody = array_merge($providerRequest->body, ['stream' => true]);
            $providerRequest = new \App\Components\PromptAssembler\DTO\AssembledPayload(
                body: $streamBody,
                headers: $providerRequest->headers,
            );

            $pendingPrompt->update([
                'assembled_payload' => $providerRequest->toArray(),
            ]);

            // 4.1 Check token budget BEFORE sending (Claude only)
            if ($resolvedProvider->providerName === 'claude') {
                $estimatedTokens = $claudeTokenEstimator->estimate($providerRequest);
                $tokenBudget = $claudeTokenBudget->check($estimatedTokens);
                if (!$tokenBudget->allowed) {
                    Log::channel('llm')->warning('provider.token_budget_exceeded', [
                        'request_id' => $requestLog->request_id,
                        'provider' => 'claude',
                        'estimated_tokens' => $estimatedTokens,
                        'remaining_tokens' => $tokenBudget->remaining,
                        'retry_after' => $tokenBudget->retryAfter,
                    ]);

                    $this->release($tokenBudget->retryAfter ?? self::REQUEUE_DELAY);
                    return;
                }
            }

            // 5. Send streaming request to provider
            $timeoutSeconds = $parsed->callback->timeout ?? 300;
            $psrResponse = $streamReader->sendStreaming($providerRequest, $resolvedProvider, $timeoutSeconds);

            // 5.1 Record token usage from response headers (Claude only)
            if ($resolvedProvider->providerName === 'claude') {
                $claudeTokenBudget->recordUsage($psrResponse->getHeaders());
            }

            // 6. Determine stream parser based on provider
            $providerName = ProviderName::tryFrom($resolvedProvider->providerName) ?? ProviderName::Claude;
            $streamGenerator = match ($providerName) {
                ProviderName::Claude => $streamHandler->handleClaudeStream($psrResponse, $requestLog->request_id),
                default => $streamHandler->handleOpenAiStream($psrResponse, $requestLog->request_id),
            };

            // 7. Iterate stream and deliver each chunk
            $callbackUrl = $parsed->callback->url;
            $callbackMethod = $parsed->callback->method;
            $callbackHeaders = $parsed->callback->headers;
            $signingSecret = $requestLog->apiClient->signing_secret;
            $requestId = $requestLog->request_id;

            $fullContent = '';
            $finalUsage = null;
            $finalFinishReason = null;
            $hasError = false;
            $errorCode = null;
            $errorMessage = null;

            foreach ($streamGenerator as $chunk) {
                /** @var StreamChunk $chunk */
                $streamingDelivery->sendEvent(
                    $callbackUrl,
                    $callbackMethod,
                    $callbackHeaders,
                    $chunk,
                    $requestId,
                    $signingSecret,
                );

                match ($chunk->type) {
                    'token' => $fullContent .= $chunk->content ?? '',
                    'done' => (function () use ($chunk, &$finalUsage, &$finalFinishReason) {
                        $finalUsage = $chunk->usage;
                        $finalFinishReason = $chunk->finishReason;
                    })(),
                    'error' => (function () use ($chunk, &$hasError, &$errorCode, &$errorMessage) {
                        $hasError = true;
                        $errorCode = $chunk->errorCode;
                        $errorMessage = $chunk->errorMessage;
                    })(),
                };
            }

            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            // 8. Save raw response (accumulated content)
            RawResponse::create([
                'request_log_id' => $requestLog->id,
                'provider' => $resolvedProvider->providerName,
                'model' => $resolvedProvider->modelName,
                'http_status' => $psrResponse->getStatusCode(),
                'response_body' => ['content' => $fullContent, 'stream' => true],
                'response_headers' => $psrResponse->getHeaders(),
                'is_fallback_attempt' => false,
                'duration_ms' => $latencyMs,
            ]);

            // 9. Save response_log
            $usage = $finalUsage ?? new UsageInfo(0, 0);
            ResponseLog::create([
                'request_log_id' => $requestLog->id,
                'status' => $hasError ? 'error' : 'ok',
                'finish_reason' => $hasError ? 'error' : ($finalFinishReason ?? 'end_turn'),
                'input_tokens' => $usage->inputTokens,
                'output_tokens' => $usage->outputTokens,
                'provider_used' => $resolvedProvider->providerName,
                'model_used' => $resolvedProvider->modelName,
                'is_fallback' => $resolvedProvider->isFallback,
                'latency_ms' => $latencyMs,
                'error_code' => $hasError ? $errorCode : null,
                'error_message' => $hasError ? $errorMessage : null,
            ]);

            // 10. Delete pending_prompt (response streamed)
            $pendingPrompt->delete();

            // 11. Update request_log
            $requestLog->update([
                'status' => $hasError ? RequestStatus::Failed->value : RequestStatus::Completed->value,
                'provider_used' => $resolvedProvider->providerName,
                'model_used' => $resolvedProvider->modelName,
                'is_fallback' => $resolvedProvider->isFallback,
                'latency_ms' => $latencyMs,
                'error_code' => $hasError ? $errorCode : null,
                'error_message' => $hasError ? $errorMessage : null,
            ]);

        } catch (ProviderRateLimitedException|ProviderInsufficientFundsException $e) {
            $this->handleRetryableError($requestLog, $e, $throttle);

        } catch (\Throwable $e) {
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            $requestLog->update([
                'status' => RequestStatus::Failed->value,
                'error_code' => 'STREAM_ERROR',
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
                'latency_ms' => $latencyMs,
            ]);

            // Send stream_error event to callback
            try {
                $errorChunk = new StreamChunk(
                    type: 'error',
                    content: null,
                    index: 0,
                    finishReason: null,
                    usage: null,
                    errorCode: 'STREAM_ERROR',
                    errorMessage: mb_substr($e->getMessage(), 0, 500),
                );

                $client = $requestLog->apiClient;
                if ($client && $requestLog->callback_url) {
                    $streamingDelivery->sendEvent(
                        $requestLog->callback_url,
                        'POST',
                        [],
                        $errorChunk,
                        $requestLog->request_id,
                        $client->signing_secret,
                    );
                }
            } catch (\Throwable) {
                // Best effort — error delivery failed
            }

            // Save error response_log
            ResponseLog::create([
                'request_log_id' => $requestLog->id,
                'status' => 'error',
                'error_code' => 'STREAM_ERROR',
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
                'provider_used' => $requestLog->provider_used ?? 'unknown',
                'model_used' => $requestLog->model_used ?? 'unknown',
                'latency_ms' => $latencyMs,
            ]);
        }
    }

    /**
     * Обрабатывает retryable ошибки (rate limit, insufficient funds):
     * — логирует, ставит провайдера на паузу, возвращает job в очередь.
     * Очередь для этого провайдера остановлена до ручного вызова llm:resume-provider.
     */
    private function handleRetryableError(
        RequestLog $requestLog,
        ProviderRateLimitedException|ProviderInsufficientFundsException $e,
        RequestThrottle $throttle,
    ): void {
        $providerName = $e->providerName ?? $requestLog->provider_requested ?? 'unknown';
        $reason = $e instanceof ProviderInsufficientFundsException ? 'insufficient_funds' : 'rate_limit';

        $delay = ($e instanceof ProviderRateLimitedException && $e->retryAfter)
            ? $e->retryAfter
            : self::REQUEUE_DELAY;

        if ($e instanceof ProviderRateLimitedException && $e->retryAfter) {
            Log::channel('llm')->warning("provider.{$reason}_temporary_pause", [
                'request_id' => $requestLog->request_id,
                'provider' => $providerName,
                'error_code' => $e->errorCode,
                'retry_after' => $delay,
                'message' => $e->getMessage(),
            ]);

            Cache::put("provider_paused:{$providerName}", [
                'reason' => $reason,
                'paused_at' => now()->toIso8601String(),
                'auto_resume_at' => now()->addSeconds($delay)->toIso8601String(),
            ], $delay);
        } else {
            Log::channel('llm')->error("provider.{$reason}_paused", [
                'request_id' => $requestLog->request_id,
                'provider' => $providerName,
                'error_code' => $e->errorCode,
                'message' => $e->getMessage(),
                'action' => "Provider '{$providerName}' paused. Run: php artisan llm:resume-provider {$providerName}",
            ]);

            $throttle->pauseProvider($providerName, $reason);
        }

        $this->release($delay);
    }

    private function handleDevMode(
        RequestLog $requestLog,
        float $startTime,
        StreamingDelivery $streamingDelivery,
    ): void {
        $devModeBuilder = app(DevModeResponseBuilder::class);

        // 1. Simulate latency
        usleep(config('llm.dev_mode.latency_ms') * 1000);

        // 2. Build and send stream chunks
        $chunks = $devModeBuilder->buildStreamChunks();
        $signingSecret = $requestLog->apiClient->signing_secret;
        $requestId = $requestLog->request_id;

        foreach ($chunks as $chunk) {
            $streamingDelivery->sendEvent(
                $requestLog->callback_url,
                'POST',
                [],
                $chunk,
                $requestId,
                $signingSecret,
            );
        }

        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        // 3. Save raw response
        RawResponse::create([
            'request_log_id' => $requestLog->id,
            'provider' => config('llm.dev_mode.provider'),
            'model' => config('llm.dev_mode.model'),
            'http_status' => null,
            'response_body' => ['content' => config('llm.dev_mode.content'), 'stream' => true],
            'response_headers' => [],
            'is_fallback_attempt' => false,
            'duration_ms' => $latencyMs,
        ]);

        // 4. Save response_log
        ResponseLog::create([
            'request_log_id' => $requestLog->id,
            'status' => 'ok',
            'finish_reason' => config('llm.dev_mode.finish_reason'),
            'input_tokens' => config('llm.dev_mode.input_tokens'),
            'output_tokens' => config('llm.dev_mode.output_tokens'),
            'provider_used' => config('llm.dev_mode.provider'),
            'model_used' => config('llm.dev_mode.model'),
            'is_fallback' => false,
            'latency_ms' => $latencyMs,
        ]);

        // 5. Delete pending_prompt
        $requestLog->pendingPrompt?->delete();

        // 6. Update request_log
        $requestLog->update([
            'status' => RequestStatus::Completed->value,
            'provider_used' => config('llm.dev_mode.provider'),
            'model_used' => config('llm.dev_mode.model'),
            'is_fallback' => false,
            'latency_ms' => $latencyMs,
        ]);
    }

    private function rebuildParsedRequest(
        RequestLog $requestLog,
        \App\Models\PendingPrompt $pendingPrompt,
        XmlParser $xmlParser,
    ): ParsedRequest {
        $xml = '<llm_request version="3.0">';
        $xml .= '<meta>';
        $meta = $requestLog->meta_data ?? [];
        $xml .= '<request_id>' . htmlspecialchars($meta['request_id'] ?? $requestLog->request_id) . '</request_id>';
        if ($requestLog->session_id) {
            $xml .= '<session_id>' . htmlspecialchars($requestLog->session_id) . '</session_id>';
        }
        if ($requestLog->step_id) {
            $xml .= '<step_id>' . $requestLog->step_id . '</step_id>';
        }
        $xml .= '</meta>';

        if ($pendingPrompt->provider_xml) {
            $xml .= $pendingPrompt->provider_xml;
        } elseif ($requestLog->provider_requested || $requestLog->model_requested) {
            $xml .= '<provider';
            if ($requestLog->provider_requested) {
                $xml .= ' name="' . htmlspecialchars($requestLog->provider_requested) . '"';
            }
            if ($requestLog->model_requested) {
                $xml .= ' model="' . htmlspecialchars($requestLog->model_requested) . '"';
            }
            $xml .= '/>';
        }

        $xml .= $pendingPrompt->prompt_xml;

        if ($pendingPrompt->tools_xml) {
            $xml .= $pendingPrompt->tools_xml;
        }

        if ($pendingPrompt->parameters_xml) {
            $xml .= $pendingPrompt->parameters_xml;
        }

        $xml .= '<callback>';
        $xml .= '<url>' . htmlspecialchars($requestLog->callback_url) . '</url>';
        $xml .= '</callback>';

        $xml .= '</llm_request>';

        return $xmlParser->parse($xml);
    }
}
