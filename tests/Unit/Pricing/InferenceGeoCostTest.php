<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Components\Claude\DTO\UsageData;
use App\Components\Pricing\CostCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InferenceGeoCostTest extends TestCase
{
    private array $pricingConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pricingConfig = [
            'claude-opus' => [
                'input' => 5.00,
                'output' => 25.00,
                'cache_write_5m' => 6.25,
                'cache_write_1h' => 10.00,
                'cache_read' => 0.50,
                'batch_input' => 2.50,
                'batch_output' => 12.50,
            ],
            'server_tools' => ['web_search_per_1k' => 10.0],
        ];
    }

    #[Test]
    public function inference_geo_us_applies_multiplier_to_input_and_output(): void
    {
        $calculator = new CostCalculator($this->pricingConfig, 1.10);

        $usage = new UsageData(
            inputTokens: 1_000_000,
            outputTokens: 500_000,
            cacheCreation5mTokens: 0,
            cacheCreation1hTokens: 0,
            cacheReadTokens: 0,
            thinkingTokens: 0,
            serverToolWebSearchCount: 0,
            serverToolWebFetchCount: 0,
            serverToolCodeExecCount: 0,
            serverToolToolSearchCount: 0,
            iterations: [],
            totalInputTokens: 1_000_000,
            totalOutputTokens: 500_000,
        );

        $result = $calculator->calculate($usage, 'claude-opus', false, true);

        // Input: 1M * 5.00 / 1M * 1.10 = 5.50
        $inputCostAdjusted = $result->inputCost->multiply('1.10');
        // Output: 500K * 25.00 / 1M * 1.10 = 13.75
        $outputCostAdjusted = $result->outputCost->multiply('1.10');

        $expectedTotal = $inputCostAdjusted->add($outputCostAdjusted);
        $this->assertSame($expectedTotal->amountUsd, $result->totalCost->amountUsd);
    }

    #[Test]
    public function inference_geo_us_does_not_apply_to_cache(): void
    {
        $calculator = new CostCalculator($this->pricingConfig, 1.10);

        $usage = new UsageData(
            inputTokens: 1_000_000,
            outputTokens: 0,
            cacheCreation5mTokens: 100_000,
            cacheCreation1hTokens: 0,
            cacheReadTokens: 200_000,
            thinkingTokens: 0,
            serverToolWebSearchCount: 0,
            serverToolWebFetchCount: 0,
            serverToolCodeExecCount: 0,
            serverToolToolSearchCount: 0,
            iterations: [],
            totalInputTokens: 1_000_000,
            totalOutputTokens: 0,
        );

        $withGeo = $calculator->calculate($usage, 'claude-opus', false, true);
        $withoutGeo = $calculator->calculate($usage, 'claude-opus', false, false);

        // Cache costs should be same regardless of geo
        $this->assertSame($withGeo->cacheWrite5mCost->amountUsd, $withoutGeo->cacheWrite5mCost->amountUsd);
        $this->assertSame($withGeo->cacheReadCost->amountUsd, $withoutGeo->cacheReadCost->amountUsd);

        // Input cost should differ
        $this->assertNotSame($withGeo->totalCost->amountUsd, $withoutGeo->totalCost->amountUsd);
    }
}
