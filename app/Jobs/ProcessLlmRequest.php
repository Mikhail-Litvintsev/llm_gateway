<?php

namespace App\Jobs;

use App\Components\DevMode\DevModeResponseBuilder;
use App\Components\PromptAssembler\DTO\AssembledPayload;
use App\Components\PromptAssembler\PromptAssembler;
use App\Components\CallbackDelivery\ErrorCallbackBuilder;
use App\Components\ProviderGateway\DTO\ProviderResponse;
use App\Components\ProviderGateway\DTO\RawProviderResponse;
use App\Components\ProviderGateway\DTO\ResolvedProvider;
use App\Components\ProviderGateway\Exceptions\ProviderException;
use App\Components\ProviderGateway\Exceptions\ProviderInsufficientFundsException;
use App\Components\ProviderGateway\Exceptions\ProviderRateLimitedException;
use App\Components\ProviderGateway\ProviderGateway;
use App\Components\ProviderGateway\ResponseParser;
use App\Components\RateLimiter\Claude\ClaudeTokenBudget;
use App\Components\RateLimiter\Claude\ClaudeTokenEstimator;
use App\Components\RateLimiter\RequestThrottle;
use App\Components\RequestPipeline\DTO\CallbackConfig;
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

class ProcessLlmRequest implements ShouldQueue
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
        ResponseParser $responseParser,
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
            $this->handleDevMode($requestLog, $startTime);
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

            // 3. Resolve provider (handle auto)
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

            // 4. Assemble payload for provider
            $providerRequest = $promptAssembler->assemble($parsed, $resolvedProvider);

            // Save assembled payload for debugging
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

            // 5. Send request to LLM
            $timeoutSeconds = $parsed->callback->timeout ?? 300;
            $rawResponse = $providerGateway->send($providerRequest, $resolvedProvider, $timeoutSeconds);

            // 6. Save raw response
            RawResponse::create([
                'request_log_id' => $requestLog->id,
                'provider' => $resolvedProvider->providerName,
                'model' => $resolvedProvider->modelName,
                'http_status' => $rawResponse->httpStatus,
                'response_body' => $rawResponse->body,
                'response_headers' => $rawResponse->headers,
                'is_fallback_attempt' => false,
                'duration_ms' => $rawResponse->durationMs,
            ]);

            // 6.1 Record token usage from Anthropic response headers (Claude only)
            if ($resolvedProvider->providerName === 'claude') {
                $claudeTokenBudget->recordUsage($rawResponse->headers);
            }

            // 7. If provider error — try fallback
            if (!$rawResponse->isSuccess()) {
                $fallbackResponse = $providerGateway->getFallbackExecutor()->tryFallback(
                    $parsed->provider?->fallback,
                    $parsed,
                    fn (AssembledPayload $payload, ResolvedProvider $prov) => $providerGateway->send($payload, $prov, $timeoutSeconds),
                    $requestLog->id,
                );

                if ($fallbackResponse && $fallbackResponse->isSuccess()) {
                    $rawResponse = $fallbackResponse;
                    // Update resolved provider to fallback
                    $resolvedProvider = new ResolvedProvider(
                        providerName: $resolvedProvider->providerName,
                        modelName: $resolvedProvider->modelName,
                        endpoint: $resolvedProvider->endpoint,
                        apiKey: $resolvedProvider->apiKey,
                        isFallback: true,
                    );
                }
            }

            // 8. Parse response
            $isFallback = $providerRequest->structuredOutputFallback;
            $providerResponse = $responseParser->parse($rawResponse, $resolvedProvider, $isFallback);

            // 9. Save response_log
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);
            $this->saveResponseLog($requestLog, $providerResponse, $resolvedProvider, $latencyMs);

            // 10. Build callback payload
            $callbackPayload = $this->buildCallbackPayload(
                $requestLog, $providerResponse, $resolvedProvider, $latencyMs,
            );

            // 11. Save to pending_responses
            $this->savePendingResponse($requestLog, $callbackPayload, $parsed->callback);

            // 12. Delete pending_prompt (response received)
            $pendingPrompt->delete();

            // 13. Update request_log
            $requestLog->update([
                'status' => RequestStatus::Completed->value,
                'provider_used' => $resolvedProvider->providerName,
                'model_used' => $resolvedProvider->modelName,
                'is_fallback' => $resolvedProvider->isFallback,
                'latency_ms' => $latencyMs,
            ]);

            // 14. Dispatch callback delivery
            DeliverCallback::dispatch($requestLog->id);

        } catch (ProviderRateLimitedException|ProviderInsufficientFundsException $e) {
            $this->handleRetryableError($requestLog, $e, $throttle);

        } catch (ProviderException $e) {
            Log::channel('llm')->error('provider.request_failed', [
                'request_id' => $requestLog->request_id,
                'provider' => $requestLog->provider_requested,
                'error_code' => $e->errorCode,
                'exception' => $e->getMessage(),
            ]);

            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            $requestLog->update([
                'status' => RequestStatus::Failed->value,
                'error_code' => $e->errorCode,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
                'latency_ms' => $latencyMs,
            ]);

            $this->dispatchErrorCallback($requestLog, $e->errorCode, $e->getMessage(), $latencyMs, $e->details);

        } catch (\Throwable $e) {
            Log::channel('llm')->error('job.unhandled_exception', [
                'request_id' => $requestLog->request_id,
                'exception' => $e->getMessage(),
            ]);

            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            $requestLog->update([
                'status' => RequestStatus::Failed->value,
                'error_code' => 'INTERNAL_ERROR',
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
                'latency_ms' => $latencyMs,
            ]);

            $this->dispatchErrorCallback($requestLog, 'INTERNAL_ERROR', $e->getMessage(), $latencyMs);
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

    private function handleDevMode(RequestLog $requestLog, float $startTime): void
    {
        $devModeBuilder = app(DevModeResponseBuilder::class);

        // 1. Simulate latency
        usleep(config('llm.dev_mode.latency_ms') * 1000);

        $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

        // 2. Build stub response
        $providerResponse = $devModeBuilder->buildProviderResponse();
        $callbackPayload = $devModeBuilder->buildCallbackPayload($requestLog, $latencyMs);

        // 3. Save response_log
        ResponseLog::create([
            'request_log_id' => $requestLog->id,
            'status' => 'ok',
            'finish_reason' => $providerResponse->finishReason,
            'input_tokens' => $providerResponse->usage->inputTokens,
            'output_tokens' => $providerResponse->usage->outputTokens,
            'cache_creation_tokens' => 0,
            'cache_read_tokens' => 0,
            'has_tool_calls' => false,
            'tool_calls_count' => 0,
            'provider_used' => config('llm.dev_mode.provider'),
            'model_used' => config('llm.dev_mode.model'),
            'is_fallback' => false,
            'latency_ms' => $latencyMs,
            'structured_output_fallback' => false,
        ]);

        // 4. Save pending_response
        PendingResponse::create([
            'request_log_id' => $requestLog->id,
            'response_payload' => $callbackPayload,
            'callback_url' => $requestLog->callback_url,
            'callback_method' => 'POST',
            'callback_headers' => [],
            'delivery_status' => 'pending',
            'delivery_attempts' => 0,
            'max_attempts' => config('llm.callback.default_max_attempts', 3),
            'retry_backoff' => config('llm.callback.default_backoff', 'exponential'),
            'retry_initial_delay' => config('llm.callback.default_initial_delay', 1),
            'expires_at' => now()->addSeconds(config('llm.pending_ttl', 259200)),
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

        // 7. Dispatch callback delivery
        DeliverCallback::dispatch($requestLog->id);
    }

    private function rebuildParsedRequest(
        RequestLog $requestLog,
        \App\Models\PendingPrompt $pendingPrompt,
        XmlParser $xmlParser,
    ): ParsedRequest {
        // Reconstruct XML from stored pieces to re-parse
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

        // Provider section (use stored XML to preserve fallback config)
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

        // Prompt (stored XML)
        $xml .= $pendingPrompt->prompt_xml;

        // Tools (stored XML)
        if ($pendingPrompt->tools_xml) {
            $xml .= $pendingPrompt->tools_xml;
        }

        // Parameters (stored XML)
        if ($pendingPrompt->parameters_xml) {
            $xml .= $pendingPrompt->parameters_xml;
        }

        // Callback
        $xml .= '<callback>';
        $xml .= '<url>' . htmlspecialchars($requestLog->callback_url) . '</url>';
        $xml .= '</callback>';

        $xml .= '</llm_request>';

        return $xmlParser->parse($xml);
    }

    private function saveResponseLog(
        RequestLog $requestLog,
        ProviderResponse $response,
        ResolvedProvider $provider,
        int $latencyMs,
    ): ResponseLog {
        return ResponseLog::create([
            'request_log_id' => $requestLog->id,
            'status' => $response->finishReason === 'error' ? 'error' : 'ok',
            'finish_reason' => $response->finishReason,
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
            'cache_creation_tokens' => $response->usage->cacheCreationTokens,
            'cache_read_tokens' => $response->usage->cacheReadTokens,
            'reasoning_tokens' => $response->reasoning['tokens'] ?? null,
            'has_tool_calls' => !empty($response->toolCalls),
            'tool_calls_count' => count($response->toolCalls),
            'provider_used' => $provider->providerName,
            'model_used' => $provider->modelName,
            'is_fallback' => $provider->isFallback,
            'latency_ms' => $latencyMs,
            'structured_output_fallback' => $response->structuredOutputFallback,
        ]);
    }

    private function buildCallbackPayload(
        RequestLog $requestLog,
        ProviderResponse $response,
        ResolvedProvider $provider,
        int $latencyMs,
    ): array {
        return [
            'status' => 'ok',
            'meta' => $requestLog->meta_data,
            'provider' => [
                'name' => $provider->providerName,
                'model' => $provider->modelName,
                'is_fallback' => $provider->isFallback,
            ],
            'result' => [
                'content' => $response->content,
                'tool_calls' => array_map(fn ($tc) => $tc->toArray(), $response->toolCalls),
                'finish_reason' => $response->finishReason,
                'usage' => [
                    'input_tokens' => $response->usage->inputTokens,
                    'output_tokens' => $response->usage->outputTokens,
                    'cache_creation_input_tokens' => $response->usage->cacheCreationTokens,
                    'cache_read_input_tokens' => $response->usage->cacheReadTokens,
                ],
                'reasoning' => $response->reasoning,
            ],
            'structured_output_fallback' => $response->structuredOutputFallback,
            'latency_ms' => $latencyMs,
        ];
    }

    private function savePendingResponse(
        RequestLog $requestLog,
        array $callbackPayload,
        CallbackConfig $callback,
    ): void {
        PendingResponse::create([
            'request_log_id' => $requestLog->id,
            'response_payload' => $callbackPayload,
            'callback_url' => $callback->url,
            'callback_method' => $callback->method,
            'callback_headers' => $callback->headers,
            'delivery_status' => 'pending',
            'delivery_attempts' => 0,
            'max_attempts' => $callback->retry->maxAttempts,
            'retry_backoff' => $callback->retry->backoff,
            'retry_initial_delay' => $callback->retry->initialDelay,
            'expires_at' => now()->addSeconds(config('llm.pending_ttl', 259200)),
        ]);
    }

    private function dispatchErrorCallback(
        RequestLog $requestLog,
        string $errorCode,
        string $errorMessage,
        int $latencyMs,
        array $details = [],
    ): void {
        $callbackUrl = $requestLog->callback_url;
        if (!$callbackUrl) {
            return;
        }

        $errorBuilder = app(ErrorCallbackBuilder::class);

        $callbackPayload = $errorBuilder->build(
            $requestLog->meta_data,
            $errorCode,
            mb_substr($errorMessage, 0, 1000),
            $details,
            $latencyMs,
        );

        PendingResponse::create([
            'request_log_id' => $requestLog->id,
            'response_payload' => $callbackPayload,
            'callback_url' => $callbackUrl,
            'callback_method' => 'POST',
            'callback_headers' => [],
            'delivery_status' => 'pending',
            'delivery_attempts' => 0,
            'max_attempts' => config('llm.callback.default_max_attempts', 3),
            'retry_backoff' => config('llm.callback.default_backoff', 'exponential'),
            'retry_initial_delay' => config('llm.callback.default_initial_delay', 1),
            'expires_at' => now()->addSeconds(config('llm.pending_ttl', 259200)),
        ]);

        // Save error response_log
        ResponseLog::create([
            'request_log_id' => $requestLog->id,
            'status' => 'error',
            'error_code' => $errorCode,
            'error_message' => mb_substr($errorMessage, 0, 1000),
            'provider_used' => $requestLog->provider_used ?? 'unknown',
            'model_used' => $requestLog->model_used ?? 'unknown',
            'latency_ms' => $latencyMs,
        ]);

        DeliverCallback::dispatch($requestLog->id);
    }
}
