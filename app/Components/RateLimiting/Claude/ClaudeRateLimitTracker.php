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
        RateLimitNamespace $ns,
        string $workspaceKeyHash,
        string $modelSnapshot,
        int $estimatedInputTokens,
        int $estimatedOutputTokens,
        int $expectedCacheReadTokens,
    ): void {
        $snapshot = $this->snapshot($ns, $workspaceKeyHash, $modelSnapshot);

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

        if ($ns === RateLimitNamespace::Messages) {
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
    }

    public function recordFromHeaders(
        RateLimitNamespace $ns,
        string $workspaceKeyHash,
        string $modelSnapshot,
        array $responseHeaders,
    ): void {
        $headers = $this->normalizeHeaders($responseHeaders);

        if ($ns === RateLimitNamespace::BatchCreate) {
            $this->recordBatchHeaders($workspaceKeyHash, $modelSnapshot, $headers);

            return;
        }

        if (!isset($headers['anthropic-ratelimit-requests-limit'])) {
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
        $key = $this->redisKey($ns, $workspaceKeyHash, $modelSnapshot);

        Redis::setex($key, $ttl, json_encode($this->snapshotToArray($snapshot), JSON_THROW_ON_ERROR));

        if (isset($headers['anthropic-priority-ratelimit-requests-limit'])) {
            $this->recordPriorityHeaders($workspaceKeyHash, $modelSnapshot, $headers);
        }

        if (isset($headers['anthropic-fast-ratelimit-requests-limit'])) {
            $this->recordPrefixedHeaders(RateLimitNamespace::Fast, 'anthropic-fast-ratelimit-', $workspaceKeyHash, $modelSnapshot, $headers);
        }
    }

    public function snapshot(RateLimitNamespace $ns, string $workspaceKeyHash, string $modelSnapshot): ?RateLimitSnapshot
    {
        $key = $this->redisKey($ns, $workspaceKeyHash, $modelSnapshot);
        $raw = Redis::get($key);

        if ($raw === null) {
            return null;
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return new RateLimitSnapshot(
            requestsLimit: $data['requests_limit'],
            requestsRemaining: $data['requests_remaining'],
            requestsResetAt: new DateTimeImmutable($data['requests_reset_at']),
            tokensLimit: $data['tokens_limit'] ?? 0,
            tokensRemaining: $data['tokens_remaining'] ?? 0,
            tokensResetAt: new DateTimeImmutable($data['tokens_reset_at'] ?? 'now'),
            inputTokensLimit: $data['input_tokens_limit'] ?? 0,
            inputTokensRemaining: $data['input_tokens_remaining'] ?? 0,
            inputTokensResetAt: new DateTimeImmutable($data['input_tokens_reset_at'] ?? 'now'),
            outputTokensLimit: $data['output_tokens_limit'] ?? 0,
            outputTokensRemaining: $data['output_tokens_remaining'] ?? 0,
            outputTokensResetAt: new DateTimeImmutable($data['output_tokens_reset_at'] ?? 'now'),
            recordedAt: new DateTimeImmutable($data['recorded_at']),
        );
    }

    private function recordPriorityHeaders(string $workspaceKeyHash, string $modelSnapshot, array $headers): void
    {
        $this->recordPrefixedHeaders(RateLimitNamespace::Priority, 'anthropic-priority-ratelimit-', $workspaceKeyHash, $modelSnapshot, $headers);
    }

    private function recordPrefixedHeaders(
        RateLimitNamespace $ns,
        string $prefix,
        string $workspaceKeyHash,
        string $modelSnapshot,
        array $headers,
    ): void {
        $now = new DateTimeImmutable;

        $snapshot = new RateLimitSnapshot(
            requestsLimit: (int) ($headers[$prefix . 'requests-limit'] ?? 0),
            requestsRemaining: (int) ($headers[$prefix . 'requests-remaining'] ?? 0),
            requestsResetAt: new DateTimeImmutable($headers[$prefix . 'requests-reset'] ?? 'now'),
            tokensLimit: (int) ($headers[$prefix . 'tokens-limit'] ?? 0),
            tokensRemaining: (int) ($headers[$prefix . 'tokens-remaining'] ?? 0),
            tokensResetAt: new DateTimeImmutable($headers[$prefix . 'tokens-reset'] ?? 'now'),
            inputTokensLimit: (int) ($headers[$prefix . 'input-tokens-limit'] ?? 0),
            inputTokensRemaining: (int) ($headers[$prefix . 'input-tokens-remaining'] ?? 0),
            inputTokensResetAt: new DateTimeImmutable($headers[$prefix . 'input-tokens-reset'] ?? 'now'),
            outputTokensLimit: (int) ($headers[$prefix . 'output-tokens-limit'] ?? 0),
            outputTokensRemaining: (int) ($headers[$prefix . 'output-tokens-remaining'] ?? 0),
            outputTokensResetAt: new DateTimeImmutable($headers[$prefix . 'output-tokens-reset'] ?? 'now'),
            recordedAt: $now,
        );

        $maxReset = max(
            $snapshot->requestsResetAt->getTimestamp(),
            $snapshot->inputTokensResetAt->getTimestamp(),
            $snapshot->outputTokensResetAt->getTimestamp(),
        );

        $ttl = max(1, $maxReset - $now->getTimestamp() + 5);
        $key = $this->redisKey($ns, $workspaceKeyHash, $modelSnapshot);

        Redis::setex($key, $ttl, json_encode($this->snapshotToArray($snapshot), JSON_THROW_ON_ERROR));
    }

    private function recordBatchHeaders(string $workspaceKeyHash, string $modelSnapshot, array $headers): void
    {
        if (!isset($headers['anthropic-ratelimit-batches-remaining'])) {
            return;
        }

        $now = new DateTimeImmutable;
        $resetAt = new DateTimeImmutable($headers['anthropic-ratelimit-batches-reset'] ?? 'now');

        $snapshot = new RateLimitSnapshot(
            requestsLimit: (int) ($headers['anthropic-ratelimit-batches-limit'] ?? 0),
            requestsRemaining: (int) $headers['anthropic-ratelimit-batches-remaining'],
            requestsResetAt: $resetAt,
            tokensLimit: 0,
            tokensRemaining: 0,
            tokensResetAt: $now,
            inputTokensLimit: 0,
            inputTokensRemaining: 0,
            inputTokensResetAt: $now,
            outputTokensLimit: 0,
            outputTokensRemaining: 0,
            outputTokensResetAt: $now,
            recordedAt: $now,
        );

        $ttl = max(1, $resetAt->getTimestamp() - $now->getTimestamp() + 5);
        $key = $this->redisKey(RateLimitNamespace::BatchCreate, $workspaceKeyHash, $modelSnapshot);

        Redis::setex($key, $ttl, json_encode($this->snapshotToArray($snapshot), JSON_THROW_ON_ERROR));
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

    private function redisKey(RateLimitNamespace $ns, string $workspaceKeyHash, string $modelSnapshot): string
    {
        return "claude_rl:{$ns->value}:{$workspaceKeyHash}:{$modelSnapshot}";
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
