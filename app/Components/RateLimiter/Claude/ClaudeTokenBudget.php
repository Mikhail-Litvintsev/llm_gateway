<?php

namespace App\Components\RateLimiter\Claude;

use App\Components\RateLimiter\DTO\ThrottleResult;
use Illuminate\Support\Facades\Cache;

class ClaudeTokenBudget
{
    private const OUTPUT_SAFETY_THRESHOLD = 500;
    private const CACHE_TTL = 120;

    public function recordUsage(array $responseHeaders): void
    {
        $inputRemaining = $this->extractHeader($responseHeaders, 'anthropic-ratelimit-input-tokens-remaining');
        $outputRemaining = $this->extractHeader($responseHeaders, 'anthropic-ratelimit-output-tokens-remaining');
        $inputReset = $this->extractHeader($responseHeaders, 'anthropic-ratelimit-input-tokens-reset');
        $outputReset = $this->extractHeader($responseHeaders, 'anthropic-ratelimit-output-tokens-reset');

        if ($inputRemaining !== null) {
            Cache::put('token_budget:claude:input_remaining', (int) $inputRemaining, self::CACHE_TTL);
        }
        if ($outputRemaining !== null) {
            Cache::put('token_budget:claude:output_remaining', (int) $outputRemaining, self::CACHE_TTL);
        }
        if ($inputReset !== null) {
            Cache::put('token_budget:claude:input_reset', $inputReset, self::CACHE_TTL);
        }
        if ($outputReset !== null) {
            Cache::put('token_budget:claude:output_reset', $outputReset, self::CACHE_TTL);
        }
    }

    public function check(int $estimatedInputTokens): ThrottleResult
    {
        $inputRemaining = Cache::get('token_budget:claude:input_remaining');

        if ($inputRemaining === null) {
            return new ThrottleResult(
                allowed: true,
                limit: 0,
                remaining: 0,
                resetTimestamp: 0,
                retryAfter: null,
            );
        }

        // Check output budget
        $outputRemaining = Cache::get('token_budget:claude:output_remaining');
        if ($outputRemaining !== null && (int) $outputRemaining < self::OUTPUT_SAFETY_THRESHOLD) {
            $outputReset = Cache::get('token_budget:claude:output_reset');
            $retryAfter = $this->calculateRetryAfter($outputReset);

            return new ThrottleResult(
                allowed: false,
                limit: (int) config('llm.providers.claude.token_limits.output_tokens_per_minute', 0),
                remaining: (int) $outputRemaining,
                resetTimestamp: $outputReset ? strtotime($outputReset) : time() + 60,
                retryAfter: $retryAfter,
            );
        }

        if ($estimatedInputTokens > (int) $inputRemaining) {
            $inputReset = Cache::get('token_budget:claude:input_reset');
            $retryAfter = $this->calculateRetryAfter($inputReset);

            return new ThrottleResult(
                allowed: false,
                limit: (int) config('llm.providers.claude.token_limits.input_tokens_per_minute', 0),
                remaining: (int) $inputRemaining,
                resetTimestamp: $inputReset ? strtotime($inputReset) : time() + 60,
                retryAfter: $retryAfter,
            );
        }

        Cache::decrement('token_budget:claude:input_remaining', $estimatedInputTokens);

        return new ThrottleResult(
            allowed: true,
            limit: (int) config('llm.providers.claude.token_limits.input_tokens_per_minute', 0),
            remaining: (int) $inputRemaining - $estimatedInputTokens,
            resetTimestamp: 0,
            retryAfter: null,
        );
    }

    private function calculateRetryAfter(?string $resetTimestamp): int
    {
        if (!$resetTimestamp) {
            return 60;
        }
        $resetUnix = strtotime($resetTimestamp);
        $diff = $resetUnix - time();

        return max(1, $diff);
    }

    private function extractHeader(array $headers, string $name): ?string
    {
        $value = $headers[$name] ?? $headers[strtolower($name)] ?? null;
        if (is_array($value)) {
            return $value[0] ?? null;
        }

        return $value;
    }
}
