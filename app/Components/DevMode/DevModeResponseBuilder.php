<?php

namespace App\Components\DevMode;

use App\Components\ProviderGateway\DTO\ProviderResponse;
use App\Components\ProviderGateway\DTO\UsageInfo;
use App\Components\ProviderGateway\Streaming\StreamChunk;
use App\Models\RequestLog;

class DevModeResponseBuilder
{
    public function buildProviderResponse(): ProviderResponse
    {
        return new ProviderResponse(
            content: config('llm.dev_mode.content'),
            toolCalls: [],
            finishReason: config('llm.dev_mode.finish_reason'),
            usage: new UsageInfo(
                inputTokens: config('llm.dev_mode.input_tokens'),
                outputTokens: config('llm.dev_mode.output_tokens'),
            ),
            reasoning: null,
            structuredOutputFallback: false,
        );
    }

    public function buildCallbackPayload(RequestLog $requestLog, int $latencyMs): array
    {
        return [
            'status' => 'ok',
            'meta' => $requestLog->meta_data,
            'provider' => [
                'name' => config('llm.dev_mode.provider'),
                'model' => config('llm.dev_mode.model'),
                'is_fallback' => false,
            ],
            'result' => [
                'content' => config('llm.dev_mode.content'),
                'tool_calls' => [],
                'finish_reason' => config('llm.dev_mode.finish_reason'),
                'usage' => [
                    'input_tokens' => config('llm.dev_mode.input_tokens'),
                    'output_tokens' => config('llm.dev_mode.output_tokens'),
                    'cache_creation_input_tokens' => 0,
                    'cache_read_input_tokens' => 0,
                ],
                'reasoning' => null,
            ],
            'structured_output_fallback' => false,
            'latency_ms' => $latencyMs,
        ];
    }

    /** @return StreamChunk[] */
    public function buildStreamChunks(): array
    {
        $usage = new UsageInfo(
            inputTokens: config('llm.dev_mode.input_tokens'),
            outputTokens: config('llm.dev_mode.output_tokens'),
        );

        return [
            new StreamChunk(
                type: 'token',
                content: config('llm.dev_mode.content'),
                index: 0,
                finishReason: null,
                usage: null,
                errorCode: null,
                errorMessage: null,
            ),
            new StreamChunk(
                type: 'done',
                content: null,
                index: 1,
                finishReason: config('llm.dev_mode.finish_reason'),
                usage: $usage,
                errorCode: null,
                errorMessage: null,
            ),
        ];
    }
}
