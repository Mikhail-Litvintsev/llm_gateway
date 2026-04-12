<?php

declare(strict_types=1);

namespace App\Components\RateLimiting\Claude;

use App\Components\RateLimiting\Claude\DTO\RateLimitSnapshot;
use App\Components\RateLimiting\Claude\Exceptions\RateLimitExceededException;
use DateTimeImmutable;
use Illuminate\Support\Facades\Redis;

final class ClaudeRateLimitTracker
{
    private readonly int $safetyMarginPct;

    public function __construct()
    {
        $this->safetyMarginPct = (int) config('llm.claude.rate_limit.safety_margin_pct', 10);
    }

    public function canProceed(
        string $workspaceKeyHash,
        string $modelSnapshot,
        int $estimatedInputTokens,
        int $estimatedOutputTokens,
        int $expectedCacheReadTokens,
    ): void {
        $snapshot = $this->snapshot($workspaceKeyHash, $modelSnapshot);

        if ($snapshot === null) {
            return;
        }

        $now = new DateTimeImmutable;

        $this->checkAxis(
            'requests',
            $snapshot->requestsRemaining,
            1,
            $snapshot->requestsResetAt,
            $now,
        );

        $effectiveInput = max(0, $estimatedInputTokens - $expectedCacheReadTokens);

        $this->checkAxis(
            'input_tokens',
            $snapshot->inputTokensRemaining,
            $effectiveInput,
            $snapshot->inputTokensResetAt,
            $now,
        );

        $this->checkAxis(
            'output_tokens',
            $snapshot->outputTokensRemaining,
            $estimatedOutputTokens,
            $snapshot->outputTokensResetAt,
            $now,
        );
    }

    public function recordFromHeaders(
        string $workspaceKeyHash,
        string $modelSnapshot,
        array $responseHeaders,
    ): void {
        $headers = $this->normalizeHeaders($responseHeaders);

        if (! isset($headers['anthropic-ratelimit-requests-limit'])) {
            return;
        }

        $now = new DateTimeImmutable;

        $snapshot = new RateLimitSnapshot(
            requestsLimit: (int) $headers['anthropic-ratelimit-requests-limit'],
            requestsRemaining: (int) $headers['anthropic-ratelimit-requests-remaining'],
            requestsResetAt: new DateTimeImmutable($headers['anthropic-ratelimit-requests-reset']),
            tokensLimit: (int) $headers['anthropic-ratelimit-tokens-limit'],
            tokensRemaining: (int) $headers['anthropic-ratelimit-tokens-remaining'],
            tokensResetAt: new DateTimeImmutable($headers['anthropic-ratelimit-tokens-reset']),
            inputTokensLimit: (int) $headers['anthropic-ratelimit-input-tokens-limit'],
            inputTokensRemaining: (int) $headers['anthropic-ratelimit-input-tokens-remaining'],
            inputTokensResetAt: new DateTimeImmutable($headers['anthropic-ratelimit-input-tokens-reset']),
            outputTokensLimit: (int) $headers['anthropic-ratelimit-output-tokens-limit'],
            outputTokensRemaining: (int) $headers['anthropic-ratelimit-output-tokens-remaining'],
            outputTokensResetAt: new DateTimeImmutable($headers['anthropic-ratelimit-output-tokens-reset']),
            recordedAt: $now,
        );

        $maxReset = max(
            $snapshot->requestsResetAt->getTimestamp(),
            $snapshot->tokensResetAt->getTimestamp(),
            $snapshot->inputTokensResetAt->getTimestamp(),
            $snapshot->outputTokensResetAt->getTimestamp(),
        );

        $ttl = max(1, $maxReset - $now->getTimestamp() + 5);
        $key = $this->redisKey($workspaceKeyHash, $modelSnapshot);

        Redis::setex($key, $ttl, json_encode($this->snapshotToArray($snapshot), JSON_THROW_ON_ERROR));
    }

    public function snapshot(string $workspaceKeyHash, string $modelSnapshot): ?RateLimitSnapshot
    {
        $key = $this->redisKey($workspaceKeyHash, $modelSnapshot);
        $raw = Redis::get($key);

        if ($raw === null) {
            return null;
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return new RateLimitSnapshot(
            requestsLimit: $data['requests_limit'],
            requestsRemaining: $data['requests_remaining'],
            requestsResetAt: new DateTimeImmutable($data['requests_reset_at']),
            tokensLimit: $data['tokens_limit'],
            tokensRemaining: $data['tokens_remaining'],
            tokensResetAt: new DateTimeImmutable($data['tokens_reset_at']),
            inputTokensLimit: $data['input_tokens_limit'],
            inputTokensRemaining: $data['input_tokens_remaining'],
            inputTokensResetAt: new DateTimeImmutable($data['input_tokens_reset_at']),
            outputTokensLimit: $data['output_tokens_limit'],
            outputTokensRemaining: $data['output_tokens_remaining'],
            outputTokensResetAt: new DateTimeImmutable($data['output_tokens_reset_at']),
            recordedAt: new DateTimeImmutable($data['recorded_at']),
        );
    }

    private function checkAxis(
        string $axis,
        int $remaining,
        int $needed,
        DateTimeImmutable $resetAt,
        DateTimeImmutable $now,
    ): void {
        if ($resetAt <= $now) {
            return;
        }

        $effectiveLimit = (int) floor($remaining * (100 - $this->safetyMarginPct) / 100);

        if ($effectiveLimit < $needed) {
            $retryAfter = max(1, $resetAt->getTimestamp() - $now->getTimestamp());
            throw new RateLimitExceededException($axis, $retryAfter);
        }
    }

    private function redisKey(string $workspaceKeyHash, string $modelSnapshot): string
    {
        return "claude_rl:{$workspaceKeyHash}:{$modelSnapshot}";
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower((string) $key)] = is_array($value) ? $value[0] : (string) $value;
        }

        return $normalized;
    }

    private function snapshotToArray(RateLimitSnapshot $snapshot): array
    {
        return [
            'requests_limit' => $snapshot->requestsLimit,
            'requests_remaining' => $snapshot->requestsRemaining,
            'requests_reset_at' => $snapshot->requestsResetAt->format('c'),
            'tokens_limit' => $snapshot->tokensLimit,
            'tokens_remaining' => $snapshot->tokensRemaining,
            'tokens_reset_at' => $snapshot->tokensResetAt->format('c'),
            'input_tokens_limit' => $snapshot->inputTokensLimit,
            'input_tokens_remaining' => $snapshot->inputTokensRemaining,
            'input_tokens_reset_at' => $snapshot->inputTokensResetAt->format('c'),
            'output_tokens_limit' => $snapshot->outputTokensLimit,
            'output_tokens_remaining' => $snapshot->outputTokensRemaining,
            'output_tokens_reset_at' => $snapshot->outputTokensResetAt->format('c'),
            'recorded_at' => $snapshot->recordedAt->format('c'),
        ];
    }
}
