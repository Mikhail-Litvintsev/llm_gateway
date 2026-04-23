<?php

namespace Tests\Unit\Components\RateLimiter\Claude;

use App\Components\RateLimiter\Claude\ClaudeTokenBudget;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ClaudeTokenBudgetTest extends TestCase
{
    private ClaudeTokenBudget $budget;

    protected function setUp(): void
    {
        parent::setUp();
        $this->budget = new ClaudeTokenBudget();

        Cache::forget('token_budget:claude:input_remaining');
        Cache::forget('token_budget:claude:output_remaining');
        Cache::forget('token_budget:claude:input_reset');
        Cache::forget('token_budget:claude:output_reset');
    }

    public function test_allows_when_no_budget_data(): void
    {
        $result = $this->budget->check(5000);

        $this->assertTrue($result->allowed);
    }

    public function test_blocks_when_estimated_exceeds_remaining(): void
    {
        Cache::put('token_budget:claude:input_remaining', 3000, 120);

        $result = $this->budget->check(5000);

        $this->assertFalse($result->allowed);
        $this->assertEquals(3000, $result->remaining);
    }

    public function test_allows_when_estimated_fits(): void
    {
        Cache::put('token_budget:claude:input_remaining', 10000, 120);

        $result = $this->budget->check(5000);

        $this->assertTrue($result->allowed);
        $this->assertEquals(5000, $result->remaining);
    }

    public function test_atomically_decrements_remaining_on_allow(): void
    {
        Cache::put('token_budget:claude:input_remaining', 10000, 120);

        $this->budget->check(3000);

        $this->assertEquals(7000, Cache::get('token_budget:claude:input_remaining'));
    }

    public function test_concurrent_checks_decrement_correctly(): void
    {
        Cache::put('token_budget:claude:input_remaining', 10000, 120);

        $this->budget->check(3000);
        $this->budget->check(2000);

        $this->assertEquals(5000, Cache::get('token_budget:claude:input_remaining'));
    }

    public function test_blocks_when_output_remaining_below_threshold(): void
    {
        Cache::put('token_budget:claude:input_remaining', 20000, 120);
        Cache::put('token_budget:claude:output_remaining', 300, 120);

        $result = $this->budget->check(1000);

        $this->assertFalse($result->allowed);
        $this->assertEquals(300, $result->remaining);
    }

    public function test_allows_when_output_remaining_above_threshold(): void
    {
        Cache::put('token_budget:claude:input_remaining', 20000, 120);
        Cache::put('token_budget:claude:output_remaining', 5000, 120);

        $result = $this->budget->check(1000);

        $this->assertTrue($result->allowed);
    }

    public function test_records_usage_from_headers(): void
    {
        $headers = [
            'anthropic-ratelimit-input-tokens-remaining' => ['25000'],
            'anthropic-ratelimit-output-tokens-remaining' => ['7500'],
            'anthropic-ratelimit-input-tokens-reset' => ['2026-03-23T12:01:00Z'],
            'anthropic-ratelimit-output-tokens-reset' => ['2026-03-23T12:01:00Z'],
        ];

        $this->budget->recordUsage($headers);

        $this->assertEquals(25000, Cache::get('token_budget:claude:input_remaining'));
        $this->assertEquals(7500, Cache::get('token_budget:claude:output_remaining'));
        $this->assertEquals('2026-03-23T12:01:00Z', Cache::get('token_budget:claude:input_reset'));
        $this->assertEquals('2026-03-23T12:01:00Z', Cache::get('token_budget:claude:output_reset'));
    }

    public function test_retry_after_calculated_from_reset(): void
    {
        $futureReset = gmdate('Y-m-d\TH:i:s\Z', time() + 30);
        Cache::put('token_budget:claude:input_remaining', 100, 120);
        Cache::put('token_budget:claude:input_reset', $futureReset, 120);

        $result = $this->budget->check(5000);

        $this->assertFalse($result->allowed);
        $this->assertNotNull($result->retryAfter);
        $this->assertGreaterThanOrEqual(1, $result->retryAfter);
        $this->assertLessThanOrEqual(31, $result->retryAfter);
    }
}
