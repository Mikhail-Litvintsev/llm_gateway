<?php

declare(strict_types=1);

namespace Tests\Unit\Components\RateLimiting\Claude;

use App\Components\RateLimiting\Claude\ClaudeRateLimitTracker;
use App\Components\RateLimiting\Claude\Exceptions\RateLimitExceededException;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ClaudeRateLimitTrackerTest extends TestCase
{
    private ClaudeRateLimitTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('llm.claude.rate_limit.safety_margin_pct', 10);
        $this->tracker = new ClaudeRateLimitTracker;
    }

    #[Test]
    public function allows_request_when_no_prior_snapshot(): void
    {
        Redis::shouldReceive('get')
            ->once()
            ->andReturn(null);

        $this->tracker->canProceed('hash1', 'claude-sonnet-4-6', 1000, 500, 0);
        $this->assertTrue(true);
    }

    #[Test]
    public function records_headers_and_returns_snapshot(): void
    {
        $reset = now()->addMinutes(1)->toIso8601String();

        $headers = [
            'anthropic-ratelimit-requests-limit' => '1000',
            'anthropic-ratelimit-requests-remaining' => '999',
            'anthropic-ratelimit-requests-reset' => $reset,
            'anthropic-ratelimit-tokens-limit' => '100000',
            'anthropic-ratelimit-tokens-remaining' => '99000',
            'anthropic-ratelimit-tokens-reset' => $reset,
            'anthropic-ratelimit-input-tokens-limit' => '50000',
            'anthropic-ratelimit-input-tokens-remaining' => '49000',
            'anthropic-ratelimit-input-tokens-reset' => $reset,
            'anthropic-ratelimit-output-tokens-limit' => '50000',
            'anthropic-ratelimit-output-tokens-remaining' => '49000',
            'anthropic-ratelimit-output-tokens-reset' => $reset,
        ];

        Redis::shouldReceive('setex')
            ->once()
            ->withArgs(function ($key, $ttl, $json) {
                return str_contains($key, 'claude_rl:') && $ttl > 0;
            });

        $this->tracker->recordFromHeaders('hash1', 'claude-sonnet-4-6', $headers);

        Redis::shouldReceive('get')
            ->once()
            ->andReturnUsing(function () use ($headers, $reset) {
                return json_encode([
                    'requests_limit' => 1000,
                    'requests_remaining' => 999,
                    'requests_reset_at' => $reset,
                    'tokens_limit' => 100000,
                    'tokens_remaining' => 99000,
                    'tokens_reset_at' => $reset,
                    'input_tokens_limit' => 50000,
                    'input_tokens_remaining' => 49000,
                    'input_tokens_reset_at' => $reset,
                    'output_tokens_limit' => 50000,
                    'output_tokens_remaining' => 49000,
                    'output_tokens_reset_at' => $reset,
                    'recorded_at' => now()->toIso8601String(),
                ]);
            });

        $snapshot = $this->tracker->snapshot('hash1', 'claude-sonnet-4-6');

        $this->assertNotNull($snapshot);
        $this->assertSame(1000, $snapshot->requestsLimit);
        $this->assertSame(999, $snapshot->requestsRemaining);
    }

    #[Test]
    public function cache_read_tokens_subtracted_from_input_budget(): void
    {
        $reset = now()->addMinutes(1)->toIso8601String();

        Redis::shouldReceive('get')
            ->once()
            ->andReturn(json_encode([
                'requests_limit' => 1000,
                'requests_remaining' => 100,
                'requests_reset_at' => $reset,
                'tokens_limit' => 100000,
                'tokens_remaining' => 90000,
                'tokens_reset_at' => $reset,
                'input_tokens_limit' => 50000,
                'input_tokens_remaining' => 0,
                'input_tokens_reset_at' => $reset,
                'output_tokens_limit' => 50000,
                'output_tokens_remaining' => 50000,
                'output_tokens_reset_at' => $reset,
                'recorded_at' => now()->toIso8601String(),
            ]));

        // inputTokensRemaining=0, estimated=5000, cacheRead=5000 → effective=0 → no throw
        $this->tracker->canProceed('hash1', 'claude-sonnet-4-6', 5000, 100, 5000);
        $this->assertTrue(true);
    }

    #[Test]
    public function throws_when_requests_remaining_zero_and_reset_future(): void
    {
        $reset = now()->addMinutes(1)->toIso8601String();

        Redis::shouldReceive('get')
            ->once()
            ->andReturn(json_encode([
                'requests_limit' => 1000,
                'requests_remaining' => 0,
                'requests_reset_at' => $reset,
                'tokens_limit' => 100000,
                'tokens_remaining' => 90000,
                'tokens_reset_at' => $reset,
                'input_tokens_limit' => 50000,
                'input_tokens_remaining' => 50000,
                'input_tokens_reset_at' => $reset,
                'output_tokens_limit' => 50000,
                'output_tokens_remaining' => 50000,
                'output_tokens_reset_at' => $reset,
                'recorded_at' => now()->toIso8601String(),
            ]));

        $this->expectException(RateLimitExceededException::class);

        $this->tracker->canProceed('hash1', 'claude-sonnet-4-6', 100, 100, 0);
    }

    #[Test]
    public function allows_when_reset_is_past(): void
    {
        $reset = now()->subMinutes(1)->toIso8601String();

        Redis::shouldReceive('get')
            ->once()
            ->andReturn(json_encode([
                'requests_limit' => 1000,
                'requests_remaining' => 0,
                'requests_reset_at' => $reset,
                'tokens_limit' => 100000,
                'tokens_remaining' => 0,
                'tokens_reset_at' => $reset,
                'input_tokens_limit' => 50000,
                'input_tokens_remaining' => 0,
                'input_tokens_reset_at' => $reset,
                'output_tokens_limit' => 50000,
                'output_tokens_remaining' => 0,
                'output_tokens_reset_at' => $reset,
                'recorded_at' => now()->subMinutes(2)->toIso8601String(),
            ]));

        $this->tracker->canProceed('hash1', 'claude-sonnet-4-6', 5000, 5000, 0);
        $this->assertTrue(true);
    }

    #[Test]
    public function safety_margin_applied(): void
    {
        $reset = now()->addMinutes(1)->toIso8601String();

        Redis::shouldReceive('get')
            ->once()
            ->andReturn(json_encode([
                'requests_limit' => 1000,
                'requests_remaining' => 100,
                'requests_reset_at' => $reset,
                'tokens_limit' => 100000,
                'tokens_remaining' => 90000,
                'tokens_reset_at' => $reset,
                'input_tokens_limit' => 50000,
                'input_tokens_remaining' => 100,
                'input_tokens_reset_at' => $reset,
                'output_tokens_limit' => 50000,
                'output_tokens_remaining' => 50000,
                'output_tokens_reset_at' => $reset,
                'recorded_at' => now()->toIso8601String(),
            ]));

        // 10% margin: effective = floor(100 * 90/100) = 90, need 91 → throw
        $this->expectException(RateLimitExceededException::class);

        $this->tracker->canProceed('hash1', 'claude-sonnet-4-6', 91, 100, 0);
    }
}
