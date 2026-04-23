<?php

declare(strict_types=1);

namespace App\Components\Claude\Batch;

use App\Components\Claude\Batch\DTO\BatchCacheMetricsResult;
use App\Components\Claude\DTO\UsageData;

final class BatchCacheMetrics
{
    private const string SCALE = '1000000';

    /**
     * @param  iterable<UsageData>  $usageItems
     * @param  array<string, mixed>  $pricingTier
     */
    public function compute(iterable $usageItems, array $pricingTier): BatchCacheMetricsResult
    {
        $totalCacheReadTokens = 0;
        $totalInputTokens = 0;
        $totalOutputTokens = 0;
        $totalSavings = '0';

        $batchInputRate = (string) ($pricingTier['batch_input'] ?? 0);
        $cacheReadRate = (string) ($pricingTier['cache_read'] ?? 0);
        $savingsRatePerToken = bcsub($batchInputRate, $cacheReadRate, 12);

        foreach ($usageItems as $usage) {
            $totalCacheReadTokens += $usage->cacheReadTokens;
            $totalInputTokens += $usage->inputTokens;
            $totalOutputTokens += $usage->outputTokens;

            if ($usage->cacheReadTokens > 0) {
                $itemSavings = bcdiv(
                    bcmul((string) $usage->cacheReadTokens, $savingsRatePerToken, 12),
                    self::SCALE,
                    12,
                );
                $totalSavings = bcadd($totalSavings, $itemSavings, 12);
            }
        }

        $denominator = $totalCacheReadTokens + $totalInputTokens;

        $cacheHitRatio = $denominator > 0
            ? bcdiv((string) $totalCacheReadTokens, (string) $denominator, 4)
            : null;

        $savingsFormatted = $denominator > 0
            ? number_format((float) $totalSavings, 4, '.', '')
            : null;

        return new BatchCacheMetricsResult(
            totalCacheReadTokens: $totalCacheReadTokens,
            totalInputTokens: $totalInputTokens,
            totalOutputTokens: $totalOutputTokens,
            cacheHitRatio: $cacheHitRatio,
            totalSavingsFromCachingUsd: $savingsFormatted,
        );
    }
}
