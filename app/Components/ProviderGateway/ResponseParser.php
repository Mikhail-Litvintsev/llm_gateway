<?php

namespace App\Components\ProviderGateway;

use App\Components\ProviderGateway\DTO\ProviderResponse;
use App\Components\ProviderGateway\DTO\RawProviderResponse;
use App\Components\ProviderGateway\DTO\ResolvedProvider;
use App\Components\ProviderGateway\DTO\ToolCall;
use App\Components\ProviderGateway\DTO\UsageInfo;
use App\Components\ProviderGateway\Exceptions\ContentFilteredException;
use App\Components\ProviderGateway\Exceptions\ContextLengthExceededException;
use App\Components\ProviderGateway\Exceptions\ProviderException;
use App\Components\ProviderGateway\Exceptions\ProviderInsufficientFundsException;
use App\Components\ProviderGateway\Exceptions\ProviderInvalidRequestException;
use App\Components\ProviderGateway\Exceptions\ProviderRateLimitedException;
use App\Components\ProviderGateway\Exceptions\ProviderTimeoutException;
use App\Components\ProviderGateway\Exceptions\ProviderUnavailableException;

class ResponseParser
{
    public function parse(
        RawProviderResponse $raw,
        ResolvedProvider $provider,
        bool $structuredOutputFallback = false,
    ): ProviderResponse {
        if (!$raw->isSuccess()) {
            throw $this->mapProviderError($raw, $provider->providerName);
        }

        return match ($provider->providerName) {
            'claude' => $this->parseClaude($raw, $structuredOutputFallback),
            'openai', 'deepseek', 'mistral' => $this->parseOpenAiCompatible($raw, $structuredOutputFallback),
            'gemini' => $this->parseGemini($raw, $structuredOutputFallback),
            default => throw new \RuntimeException("Unknown provider: {$provider->providerName}"),
        };
    }

    private function parseClaude(RawProviderResponse $raw, bool $structuredOutputFallback): ProviderResponse
    {
        $body = $raw->body;

        $content = null;
        $toolCalls = [];
        $reasoning = null;

        foreach ($body['content'] ?? [] as $block) {
            match ($block['type'] ?? null) {
                'text' => $content = ($content !== null ? $content . "\n" : '') . ($block['text'] ?? ''),
                'tool_use' => $toolCalls[] = new ToolCall(
                    id: $block['id'] ?? '',
                    name: $block['name'] ?? '',
                    arguments: $block['input'] ?? [],
                ),
                'thinking' => $reasoning = [
                    'content' => $block['thinking'] ?? '',
                    'tokens' => null,
                ],
                default => null,
            };
        }

        $usage = $body['usage'] ?? [];

        return new ProviderResponse(
            content: $this->stripMarkdownCodeFence($content),
            toolCalls: $toolCalls,
            finishReason: $this->mapClaudeStopReason($body['stop_reason'] ?? 'end_turn'),
            usage: new UsageInfo(
                inputTokens: $usage['input_tokens'] ?? 0,
                outputTokens: $usage['output_tokens'] ?? 0,
                cacheCreationTokens: $usage['cache_creation_input_tokens'] ?? 0,
                cacheReadTokens: $usage['cache_read_input_tokens'] ?? 0,
            ),
            reasoning: $reasoning,
            structuredOutputFallback: $structuredOutputFallback,
        );
    }

    private function parseOpenAiCompatible(RawProviderResponse $raw, bool $structuredOutputFallback): ProviderResponse
    {
        $body = $raw->body;
        $choice = $body['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        $toolCalls = [];
        foreach ($message['tool_calls'] ?? [] as $tc) {
            $fn = $tc['function'] ?? [];
            $toolCalls[] = new ToolCall(
                id: $tc['id'] ?? '',
                name: $fn['name'] ?? '',
                arguments: json_decode($fn['arguments'] ?? '{}', true) ?: [],
            );
        }

        $usage = $body['usage'] ?? [];

        $reasoning = null;
        if (isset($message['reasoning_content'])) {
            $reasoning = [
                'content' => $message['reasoning_content'],
                'tokens' => $usage['completion_tokens_details']['reasoning_tokens'] ?? null,
            ];
        }

        return new ProviderResponse(
            content: $this->stripMarkdownCodeFence($message['content'] ?? null),
            toolCalls: $toolCalls,
            finishReason: $this->mapOpenAiFinishReason($choice['finish_reason'] ?? 'stop'),
            usage: new UsageInfo(
                inputTokens: $usage['prompt_tokens'] ?? 0,
                outputTokens: $usage['completion_tokens'] ?? 0,
            ),
            reasoning: $reasoning,
            structuredOutputFallback: $structuredOutputFallback,
        );
    }

    private function parseGemini(RawProviderResponse $raw, bool $structuredOutputFallback): ProviderResponse
    {
        $body = $raw->body;
        $candidate = $body['candidates'][0] ?? [];
        $parts = $candidate['content']['parts'] ?? [];

        $content = null;
        $toolCalls = [];

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $content = ($content !== null ? $content . "\n" : '') . $part['text'];
            }
            if (isset($part['functionCall'])) {
                $fc = $part['functionCall'];
                $toolCalls[] = new ToolCall(
                    id: 'fc_' . bin2hex(random_bytes(8)),
                    name: $fc['name'] ?? '',
                    arguments: $fc['args'] ?? [],
                );
            }
        }

        $usageData = $body['usageMetadata'] ?? [];

        return new ProviderResponse(
            content: $this->stripMarkdownCodeFence($content),
            toolCalls: $toolCalls,
            finishReason: $this->mapGeminiFinishReason($candidate['finishReason'] ?? 'STOP'),
            usage: new UsageInfo(
                inputTokens: $usageData['promptTokenCount'] ?? 0,
                outputTokens: $usageData['candidatesTokenCount'] ?? 0,
            ),
            reasoning: null,
            structuredOutputFallback: $structuredOutputFallback,
        );
    }

    private function mapProviderError(RawProviderResponse $raw, string $providerName): ProviderException
    {
        if ($raw->httpStatus === 0) {
            return new ProviderTimeoutException($providerName);
        }

        // HTTP 402 — Anthropic (billing_error), DeepSeek (insufficient balance)
        if ($raw->isInsufficientFunds()) {
            return new ProviderInsufficientFundsException($providerName, $raw->httpStatus);
        }

        // HTTP 429 — нужно отличить rate limit от insufficient funds (OpenAI: insufficient_quota)
        if ($raw->isRateLimited()) {
            if ($this->isInsufficientQuota($raw)) {
                return new ProviderInsufficientFundsException($providerName, $raw->httpStatus);
            }
            $retryAfter = $this->parseRetryAfter($raw->headers);
            return new ProviderRateLimitedException($providerName, $raw->httpStatus, $retryAfter);
        }

        if ($raw->httpStatus >= 500) {
            return new ProviderUnavailableException($providerName, $raw->httpStatus);
        }

        $errorType = $raw->body['error']['type'] ?? '';
        $errorCode = $raw->body['error']['code'] ?? '';

        // Gemini: billing через 400 FAILED_PRECONDITION с упоминанием billing
        if ($this->isBillingError($errorType, $errorCode, $raw->body['error']['message'] ?? '')) {
            return new ProviderInsufficientFundsException($providerName, $raw->httpStatus);
        }

        return match (true) {
            str_contains($errorType, 'context_length') || str_contains($errorType, 'max_tokens')
                => new ContextLengthExceededException($providerName),
            str_contains($errorType, 'content_filter') || str_contains($errorType, 'safety')
                => new ContentFilteredException($providerName),
            default
                => new ProviderInvalidRequestException($providerName, $raw->body['error']['message'] ?? 'Unknown error'),
        };
    }

    /**
     * OpenAI возвращает 429 с code=insufficient_quota при нехватке средств.
     */
    private function isInsufficientQuota(RawProviderResponse $raw): bool
    {
        $code = $raw->body['error']['code'] ?? '';
        $type = $raw->body['error']['type'] ?? '';

        return $code === 'insufficient_quota'
            || str_contains($type, 'insufficient_quota')
            || str_contains($code, 'billing');
    }

    /**
     * Проверяет billing-ошибки по содержимому тела ответа (Gemini и другие).
     */
    private function isBillingError(string $errorType, string $errorCode, string $errorMessage): bool
    {
        $billingIndicators = ['billing', 'payment', 'quota_exceeded', 'budget'];

        foreach ($billingIndicators as $indicator) {
            if (str_contains($errorType, $indicator)
                || str_contains($errorCode, $indicator)
                || str_contains(strtolower($errorMessage), $indicator)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Убрать markdown code fence (```json ... ```) из ответа LLM, если присутствует.
     */
    private function stripMarkdownCodeFence(?string $content): ?string
    {
        if ($content === null) {
            return null;
        }

        $trimmed = trim($content);

        if (preg_match('/^```остано(?:\w*)\n(.*)\n```$/s', $trimmed, $matches)) {
            return trim($matches[1]);
        }

        return $content;
    }

    private function parseRetryAfter(array $headers): ?int
    {
        $value = $headers['retry-after'] ?? $headers['Retry-After'] ?? null;
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }
        if ($value === null) {
            return null;
        }

        return (int) ceil((float) $value);
    }

    private function mapClaudeStopReason(string $reason): string
    {
        return match ($reason) {
            'end_turn' => 'end_turn',
            'max_tokens' => 'max_tokens',
            'tool_use' => 'tool_use',
            'stop_sequence' => 'stop_sequence',
            default => $reason,
        };
    }

    private function mapOpenAiFinishReason(string $reason): string
    {
        return match ($reason) {
            'stop' => 'end_turn',
            'length' => 'max_tokens',
            'tool_calls' => 'tool_use',
            'content_filter' => 'content_filter',
            default => $reason,
        };
    }

    private function mapGeminiFinishReason(string $reason): string
    {
        return match ($reason) {
            'STOP' => 'end_turn',
            'MAX_TOKENS' => 'max_tokens',
            'SAFETY' => 'content_filter',
            default => $reason,
        };
    }
}
