<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude\Batch;

use App\Components\Claude\Batch\BatchCacheMetrics;
use App\Components\Claude\DTO\UsageData;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('phase3-unit')]
final class BatchCacheMetricsTest extends TestCase
{
    private BatchCacheMetrics $metrics;

    private array $sonnetPricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = new BatchCacheMetrics();
        $this->sonnetPricing = [
            'input' => 3.00,
            'output' => 15.00,
            'batch_input' => 1.50,
            'batch_output' => 7.50,
            'cache_read' => 0.30,
            'cache_write_5m' => 3.75,
            'cache_write_1h' => 6.00,
        ];
    }

    #[Test]
    public function sonnet_worked_example(): void
    {
        $items = [
            $this->makeUsage(inputTokens: 1000, cacheReadTokens: 9000, outputTokens: 500),
        ];

        $result = $this->metrics->compute($items, $this->sonnetPricing);

        $this->assertSame(9000, $result->totalCacheReadTokens);
        $this->assertSame(1000, $result->totalInputTokens);
        $this->assertSame(500, $result->totalOutputTokens);

        // cache_hit_ratio = 9000 / (9000 + 1000) = 0.9000
        $this->assertSame('0.9000', $result->cacheHitRatio);

        // savings = 9000 * (1.50 - 0.30) / 1_000_000 = 9000 * 1.20 / 1_000_000 = 0.0108
        $this->assertSame('0.0108', $result->totalSavingsFromCachingUsd);
    }

    #[Test]
    public function zero_items_returns_null_ratio(): void
    {
        $result = $this->metrics->compute([], $this->sonnetPricing);

        $this->assertSame(0, $result->totalCacheReadTokens);
        $this->assertSame(0, $result->totalInputTokens);
        $this->assertNull($result->cacheHitRatio);
        $this->assertNull($result->totalSavingsFromCachingUsd);
    }

    #[Test]
    public function no_cache_reads_returns_zero_ratio(): void
    {
        $items = [
            $this->makeUsage(inputTokens: 1000, outputTokens: 500),
        ];

        $result = $this->metrics->compute($items, $this->sonnetPricing);

        $this->assertSame('0.0000', $result->cacheHitRatio);
        $this->assertSame('0.0000', $result->totalSavingsFromCachingUsd);
    }

    #[Test]
    public function mixed_items_correct_weighted_math(): void
    {
        $items = [
            $this->makeUsage(inputTokens: 500, cacheReadTokens: 4500, outputTokens: 200),
            $this->makeUsage(inputTokens: 500, cacheReadTokens: 0, outputTokens: 300),
        ];

        $result = $this->metrics->compute($items, $this->sonnetPricing);

        // total cache_read = 4500, total input = 1000
        // ratio = 4500 / (4500 + 1000) = 4500 / 5500 = 0.8181
        $this->assertSame('0.8181', $result->cacheHitRatio);

        // savings = 4500 * 1.20 / 1_000_000 = 0.0054
        $this->assertSame('0.0054', $result->totalSavingsFromCachingUsd);
    }

    private function makeUsage(
        int $inputTokens = 0,
        int $outputTokens = 0,
        int $cacheReadTokens = 0,
    ): UsageData {
        return new UsageData(
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cacheCreation5mTokens: 0,
            cacheCreation1hTokens: 0,
            cacheReadTokens: $cacheReadTokens,
            thinkingTokens: 0,
            serverToolWebSearchCount: 0,
            serverToolWebFetchCount: 0,
            serverToolCodeExecCount: 0,
            serverToolToolSearchCount: 0,
        );
    }
}
