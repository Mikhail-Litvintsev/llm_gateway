<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Components\Claude\DTO\UsageData;
use App\Components\Pricing\CostCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FastModeCostTest extends TestCase
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

    private function makeUsage(): UsageData
    {
        return new UsageData(
            inputTokens: 1_000_000,
            outputTokens: 500_000,
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
            totalOutputTokens: 500_000,
        );
    }

    #[Test]
    public function fast_mode_applies_6x_to_input_and_output(): void
    {
        $calculator = new CostCalculator($this->pricingConfig, 1.0, 1.0, 6.0);
        $usage = $this->makeUsage();

        $standard = $calculator->calculate($usage, 'claude-opus', false, false);
        $fast = $calculator->calculate($usage, 'claude-opus', false, false, null, true);

        $expectedInputFast = $standard->inputCost->multiply('6.00');
        $expectedOutputFast = $standard->outputCost->multiply('6.00');

        $expectedTotal = $expectedInputFast
            ->add($expectedOutputFast)
            ->add($standard->cacheWrite5mCost)
            ->add($standard->cacheWrite1hCost)
            ->add($standard->cacheReadCost);

        $this->assertSame($expectedTotal->amountUsd, $fast->totalCost->amountUsd);
    }

    #[Test]
    public function fast_mode_does_not_apply_to_cache(): void
    {
        $calculator = new CostCalculator($this->pricingConfig, 1.0, 1.0, 6.0);
        $usage = $this->makeUsage();

        $standard = $calculator->calculate($usage, 'claude-opus', false, false);
        $fast = $calculator->calculate($usage, 'claude-opus', false, false, null, true);

        $this->assertSame($standard->cacheWrite5mCost->amountUsd, $fast->cacheWrite5mCost->amountUsd);
        $this->assertSame($standard->cacheReadCost->amountUsd, $fast->cacheReadCost->amountUsd);
    }

    #[Test]
    public function fast_multiplier_override_changes_cost(): void
    {
        $calculator5x = new CostCalculator($this->pricingConfig, 1.0, 1.0, 5.0);
        $calculator6x = new CostCalculator($this->pricingConfig, 1.0, 1.0, 6.0);
        $usage = $this->makeUsage();

        $fast5 = $calculator5x->calculate($usage, 'claude-opus', false, false, null, true);
        $fast6 = $calculator6x->calculate($usage, 'claude-opus', false, false, null, true);

        $this->assertNotSame($fast5->totalCost->amountUsd, $fast6->totalCost->amountUsd);
    }
}
