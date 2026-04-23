<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Components\Claude\DTO\UsageData;
use App\Components\Pricing\CostCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CostCalculatorIterationsTest extends TestCase
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
            'server_tools' => ['web_search_per_1k' => 10.0],
        ];

        $this->calculator = new CostCalculator($pricingConfig, 1.0);
    }

    #[Test]
    public function iterations_input_tokens_included_in_billing(): void
    {
        $usage = new UsageData(
            inputTokens: 50,
            outputTokens: 200,
            cacheCreation5mTokens: 0,
            cacheCreation1hTokens: 0,
            cacheReadTokens: 0,
            thinkingTokens: 0,
            serverToolWebSearchCount: 0,
            serverToolWebFetchCount: 0,
            serverToolCodeExecCount: 0,
            serverToolToolSearchCount: 0,
            iterations: [['input_tokens' => 70000, 'output_tokens' => 1000]],
            totalInputTokens: 70050,
            totalOutputTokens: 1200,
        );

        $result = $this->calculator->calculate($usage, 'claude-sonnet', false, false);

        // 70050 * 3.00 / 1_000_000 = 0.210150
        $expected = bcdiv(bcmul('70050', '3.00', 12), '1000000', 12);
        $this->assertSame($expected, $result->inputCost->amountUsd);
        $this->assertSame(70050, $result->totalInputTokensBilled);
    }

    #[Test]
    public function iterations_output_tokens_included_in_billing(): void
    {
        $usage = new UsageData(
            inputTokens: 50,
            outputTokens: 200,
            cacheCreation5mTokens: 0,
            cacheCreation1hTokens: 0,
            cacheReadTokens: 0,
            thinkingTokens: 0,
            serverToolWebSearchCount: 0,
            serverToolWebFetchCount: 0,
            serverToolCodeExecCount: 0,
            serverToolToolSearchCount: 0,
            iterations: [['input_tokens' => 70000, 'output_tokens' => 1000]],
            totalInputTokens: 70050,
            totalOutputTokens: 1200,
        );

        $result = $this->calculator->calculate($usage, 'claude-sonnet', false, false);

        // 1200 * 15.00 / 1_000_000 = 0.018000
        $expected = bcdiv(bcmul('1200', '15.00', 12), '1000000', 12);
        $this->assertSame($expected, $result->outputCost->amountUsd);
        $this->assertSame(1200, $result->totalOutputTokensBilled);
    }

    #[Test]
    public function web_search_requests_priced_at_10_per_1000(): void
    {
        $usage = new UsageData(
            inputTokens: 0,
            outputTokens: 0,
            cacheCreation5mTokens: 0,
            cacheCreation1hTokens: 0,
            cacheReadTokens: 0,
            thinkingTokens: 0,
            serverToolWebSearchCount: 7,
            serverToolWebFetchCount: 0,
            serverToolCodeExecCount: 0,
            serverToolToolSearchCount: 0,
        );

        $result = $this->calculator->calculate($usage, 'claude-sonnet', false, false);

        // 7 * 10.0 / 1000 = 0.07
        $this->assertEqualsWithDelta(0.07, (float) $result->serverToolWebSearchCost->amountUsd, 0.0001);
    }

    #[Test]
    public function cost_breakdown_totals_match_sum_of_components(): void
    {
        $usage = new UsageData(
            inputTokens: 1000,
            outputTokens: 500,
            cacheCreation5mTokens: 200,
            cacheCreation1hTokens: 100,
            cacheReadTokens: 3000,
            thinkingTokens: 0,
            serverToolWebSearchCount: 3,
            serverToolWebFetchCount: 0,
            serverToolCodeExecCount: 0,
            serverToolToolSearchCount: 0,
        );

        $result = $this->calculator->calculate($usage, 'claude-sonnet', false, false);

        $sumOfComponents = $result->inputCost
            ->add($result->outputCost)
            ->add($result->cacheWrite5mCost)
            ->add($result->cacheWrite1hCost)
            ->add($result->cacheReadCost)
            ->add($result->serverToolWebSearchCost)
            ->add($result->serverToolCodeExecCost);

        $this->assertSame($sumOfComponents->amountUsd, $result->totalCost->amountUsd);
    }
}
