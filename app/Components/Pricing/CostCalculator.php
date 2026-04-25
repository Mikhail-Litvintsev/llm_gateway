<?php

declare(strict_types=1);

namespace App\Components\Pricing;

use App\Components\Claude\DTO\UsageData;
use App\Components\Pricing\DTO\CostBreakdown;
use App\Components\Pricing\DTO\Money;
use App\Components\Pricing\Exceptions\UnknownPricingTierException;

final class CostCalculator
{
    private const string SCALE = '1000000';

    /**
     * @param  array<string, mixed>  $pricingConfig
     */
    public function __construct(
        private readonly array $pricingConfig,
        private readonly float $geoUsMultiplier,
        private readonly float $priorityMultiplier = 1.0,
        private readonly float $fastMultiplier = 6.0,
    ) {}

    public function calculate(
        UsageData $usage,
        string $modelAlias,
        bool $isBatched,
        bool $inferenceGeoUs,
        ?string $serviceTierUsed = null,
        bool $isFast = false,
    ): CostBreakdown {
        $tier = $this->pricingConfig[$modelAlias] ?? null;

        if ($tier === null) {
            throw new UnknownPricingTierException($modelAlias);
        }

        $serverTools = $this->pricingConfig['server_tools'] ?? [];

        $inputRate = $isBatched ? (string) $tier['batch_input'] : (string) $tier['input'];
        $outputRate = $isBatched ? (string) $tier['batch_output'] : (string) $tier['output'];

        $inputCost = $this->tokenCost($usage->totalInputTokens ?: $usage->inputTokens, $inputRate);
        $outputCost = $this->tokenCost($usage->totalOutputTokens ?: $usage->outputTokens, $outputRate);
        $cacheWrite5mCost = $this->tokenCost($usage->cacheCreation5mTokens, (string) $tier['cache_write_5m']);
        $cacheWrite1hCost = $this->tokenCost($usage->cacheCreation1hTokens, (string) $tier['cache_write_1h']);
        $cacheReadCost = $this->tokenCost($usage->cacheReadTokens, (string) $tier['cache_read']);

        $webSearchRate = (string) ($serverTools['web_search_per_1k'] ?? 0);
        $webSearchCost = new Money(bcdiv(bcmul((string) $usage->serverToolWebSearchCount, $webSearchRate, 12), '1000', 12));
        $codeExecCost = Money::zero();

        $geoMultiplierValue = new Money($inferenceGeoUs ? (string) $this->geoUsMultiplier : '1.00');
        $priorityMultiplierValue = new Money($serviceTierUsed === 'priority' ? (string) $this->priorityMultiplier : '1.00');
        $fastMultiplierValue = new Money($isFast ? (string) $this->fastMultiplier : '1.00');

        $adjustedInput = $inputCost->multiply($geoMultiplierValue->amountUsd)->multiply($priorityMultiplierValue->amountUsd)->multiply($fastMultiplierValue->amountUsd);
        $adjustedOutput = $outputCost->multiply($geoMultiplierValue->amountUsd)->multiply($priorityMultiplierValue->amountUsd)->multiply($fastMultiplierValue->amountUsd);

        $totalCost = $adjustedInput
            ->add($adjustedOutput)
            ->add($cacheWrite5mCost)
            ->add($cacheWrite1hCost)
            ->add($cacheReadCost)
            ->add($webSearchCost)
            ->add($codeExecCost);

        return new CostBreakdown(
            inputCost: $inputCost,
            outputCost: $outputCost,
            cacheWrite5mCost: $cacheWrite5mCost,
            cacheWrite1hCost: $cacheWrite1hCost,
            cacheReadCost: $cacheReadCost,
            serverToolWebSearchCost: $webSearchCost,
            serverToolCodeExecCost: $codeExecCost,
            geoMultiplierApplied: $geoMultiplierValue,
            totalCost: $totalCost,
            totalInputTokensBilled: $usage->totalInputTokens ?: $usage->inputTokens,
            totalOutputTokensBilled: $usage->totalOutputTokens ?: $usage->outputTokens,
            iterationsSnapshot: $usage->iterations,
        );
    }

    private function tokenCost(int $tokens, string $ratePerMillion): Money
    {
        return new Money(bcdiv(bcmul((string) $tokens, $ratePerMillion, 12), self::SCALE, 12));
    }
}
