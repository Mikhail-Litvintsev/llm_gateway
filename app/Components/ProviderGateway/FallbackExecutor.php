<?php

namespace App\Components\ProviderGateway;

use App\Components\PromptAssembler\PromptAssembler;
use App\Components\ProviderGateway\DTO\RawProviderResponse;
use App\Components\ProviderGateway\DTO\ResolvedProvider;
use App\Components\RequestPipeline\DTO\ParsedRequest;
use App\Components\RequestPipeline\DTO\ProviderConfig;
use App\Models\RawResponse;

class FallbackExecutor
{
    public function __construct(
        private readonly ProviderResolver $resolver,
        private readonly PromptAssembler $assembler,
    ) {}

    /**
     * @param callable(AssembledPayload, ResolvedProvider): RawProviderResponse $sendCallback
     */
    public function tryFallback(
        ?ProviderConfig $fallbackConfig,
        ParsedRequest $parsed,
        callable $sendCallback,
        int $requestLogId,
    ): ?RawProviderResponse {
        if (!$fallbackConfig) {
            return null;
        }

        $provider = $this->resolver->resolve($fallbackConfig);
        $provider = new ResolvedProvider(
            providerName: $provider->providerName,
            modelName: $provider->modelName,
            endpoint: $provider->endpoint,
            apiKey: $provider->apiKey,
            isFallback: true,
        );

        $payload = $this->assembler->assemble($parsed, $provider);
        $response = $sendCallback($payload, $provider);

        RawResponse::create([
            'request_log_id' => $requestLogId,
            'provider' => $provider->providerName,
            'model' => $provider->modelName,
            'http_status' => $response->httpStatus,
            'response_body' => $response->body,
            'response_headers' => $response->headers,
            'is_fallback_attempt' => true,
            'duration_ms' => $response->durationMs,
        ]);

        if ($response->isSuccess()) {
            return $response;
        }

        // Recursive fallback chain
        return $this->tryFallback(
            $fallbackConfig->fallback,
            $parsed,
            $sendCallback,
            $requestLogId,
        );
    }
}
