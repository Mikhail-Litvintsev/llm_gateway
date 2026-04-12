<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Pricing;

use App\Components\Claude\DTO\UsageData;
use App\Components\Pricing\CostCalculator;
use App\Components\Pricing\Exceptions\UnknownPricingTierException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CostCalculatorTest extends TestCase
{
    private CostCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $pricingConfig = [
            'claude-sonnet' => [
                'input' => 3.00,
                'output' => 15.00,
                'cache_write_5m' => 3.75,
                'cache_write_1h' => 6.00,
                'cache_read' => 0.30,
                'batch_input' => 1.50,
                'batch_output' => 7.50,
            ],
            'server_tools' => [
                'web_search_per_1k' => 10.00,
                'web_fetch' => 0.0,
                'code_execution_free_hours_per_month' => 1550,
                'code_execution_per_hour' => 0.05,
            ],
            'inference_geo_us_multiplier' => 1.10,
        ];

        $this->calculator = new CostCalculator($pricingConfig, 1.10);
    }

    #[Test]
    public function regular_request_no_cache_no_batch_no_geo(): void
    {
        $usage = $this->makeUsage(inputTokens: 1000, outputTokens: 500);

        $result = $this->calculator->calculate($usage, 'claude-sonnet', false, false);

        // (1000 * 3 + 500 * 15) / 1_000_000 = 0.0105
        $this->assertSame('0.010500000000', $result->totalCost->amountUsd);
    }

    #[Test]
    public function batch_request_uses_batch_rates(): void
    {
        $usage = $this->makeUsage(inputTokens: 1000, outputTokens: 500);

        $result = $this->calculator->calculate($usage, 'claude-sonnet', true, false);

        // (1000 * 1.50 + 500 * 7.50) / 1_000_000 = 0.00525
        $this->assertSame('0.005250000000', $result->totalCost->amountUsd);
    }

    #[Test]
    public function cache_read_tokens(): void
    {
        $usage = $this->makeUsage(cacheReadTokens: 10000);

        $result = $this->calculator->calculate($usage, 'claude-sonnet', false, false);

        // 10000 * 0.30 / 1_000_000 = 0.003
        $this->assertSame('0.003000000000', $result->cacheReadCost->amountUsd);
    }

    #[Test]
    public function cache_write_5m_and_1h(): void
    {
        $usage = $this->makeUsage(cacheCreation5mTokens: 5000, cacheCreation1hTokens: 3000);

        $result = $this->calculator->calculate($usage, 'claude-sonnet', false, false);

        // 5000 * 3.75 / 1M = 0.01875, 3000 * 6.00 / 1M = 0.018
        $this->assertSame('0.018750000000', $result->cacheWrite5mCost->amountUsd);
        $this->assertSame('0.018000000000', $result->cacheWrite1hCost->amountUsd);
    }

    #[Test]
    public function geo_us_multiplier_applied_to_non_server_tool_costs(): void
    {
        $usage = $this->makeUsage(inputTokens: 1000, outputTokens: 500, serverToolWebSearchCount: 2);

        $result = $this->calculator->calculate($usage, 'claude-sonnet', false, true);

        // base = (1000*3 + 500*15) / 1M = 0.0105
        // geo adjusted = 0.0105 * 1.10 = 0.01155
        // web search = 2 * 10 / 1000 = 0.02
        // total = 0.01155 + 0.02 = 0.03155
        $this->assertSame('0.031550000000', $result->totalCost->amountUsd);
        $this->assertSame('1.1', $result->geoMultiplierApplied->amountUsd);
    }

    #[Test]
    public function server_tool_web_search_cost(): void
    {
        $usage = $this->makeUsage(serverToolWebSearchCount: 5);

        $result = $this->calculator->calculate($usage, 'claude-sonnet', false, false);

        // 5 * 10.00 / 1000 = 0.05
        $this->assertSame('0.050000000000', $result->serverToolWebSearchCost->amountUsd);
    }

    #[Test]
    public function money_is_not_float(): void
    {
        $usage = $this->makeUsage(inputTokens: 1000, outputTokens: 500);
        $result = $this->calculator->calculate($usage, 'claude-sonnet', false, false);

        $this->assertIsString($result->totalCost->amountUsd);
    }

    #[Test]
    public function code_execution_cost_is_zero_in_phase_1(): void
    {
        $usage = $this->makeUsage(serverToolCodeExecCount: 100);
        $result = $this->calculator->calculate($usage, 'claude-sonnet', false, false);

        $this->assertSame('0.000000000000', $result->serverToolCodeExecCost->amountUsd);
    }

    #[Test]
    public function unknown_model_alias_throws(): void
    {
        $this->expectException(UnknownPricingTierException::class);

        $usage = $this->makeUsage();
        $this->calculator->calculate($usage, 'gpt-4', false, false);
    }

    private function makeUsage(
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $cacheCreation5mTokens = 0,
        int $cacheCreation1hTokens = 0,
        int $cacheReadTokens = 0,
        int $thinkingTokens = 0,
        int $serverToolWebSearchCount = 0,
        int $serverToolWebFetchCount = 0,
        int $serverToolCodeExecCount = 0,
        int $serverToolToolSearchCount = 0,
    ): UsageData {
        return new UsageData(
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cacheCreation5mTokens: $cacheCreation5mTokens,
            cacheCreation1hTokens: $cacheCreation1hTokens,
            cacheReadTokens: $cacheReadTokens,
            thinkingTokens: $thinkingTokens,
            serverToolWebSearchCount: $serverToolWebSearchCount,
            serverToolWebFetchCount: $serverToolWebFetchCount,
            serverToolCodeExecCount: $serverToolCodeExecCount,
            serverToolToolSearchCount: $serverToolToolSearchCount,
        );
    }
}
