<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class ClaudePriceCheck extends Command
{
    protected $signature = 'claude:price-check';

    protected $description = 'Display the Claude pricing table with model aliases';

    /**
     * Dump the pricing configuration as a formatted table for manual verification.
     */
    public function handle(): int
    {
        $aliases = config('llm.claude.model_aliases', []);
        $pricing = config('llm.claude.pricing', []);

        $rows = [];
        foreach ($aliases as $alias => $snapshot) {
            $prices = $pricing[$alias] ?? [];
            if (empty($prices)) {
                continue;
            }

            $rows[] = [
                $alias,
                $snapshot,
                '$' . number_format($prices['input'] ?? 0, 2),
                '$' . number_format($prices['output'] ?? 0, 2),
                '$' . number_format($prices['cache_write_5m'] ?? 0, 2),
                '$' . number_format($prices['cache_write_1h'] ?? 0, 2),
                '$' . number_format($prices['cache_read'] ?? 0, 2),
                '$' . number_format($prices['batch_input'] ?? 0, 2),
                '$' . number_format($prices['batch_output'] ?? 0, 2),
            ];
        }

        $this->table(
            ['Alias', 'Snapshot', 'Input/MTok', 'Output/MTok', 'Cache W 5m', 'Cache W 1h', 'Cache Read', 'Batch In', 'Batch Out'],
            $rows,
        );

        $serverTools = $pricing['server_tools'] ?? [];
        if (! empty($serverTools)) {
            $this->newLine();
            $this->info('Server Tools Pricing:');
            $this->table(
                ['Tool', 'Price'],
                [
                    ['Web Search (per 1k)', '$' . number_format($serverTools['web_search_per_1k'] ?? 0, 2)],
                    ['Web Fetch', '$' . number_format($serverTools['web_fetch'] ?? 0, 2)],
                    ['Code Execution (free hrs/mo)', (string) ($serverTools['code_execution_free_hours_per_month'] ?? 0)],
                    ['Code Execution ($/hr after)', '$' . number_format($serverTools['code_execution_per_hour'] ?? 0, 2)],
                ],
            );
        }

        $geoMultiplier = $pricing['inference_geo_us_multiplier'] ?? null;
        if ($geoMultiplier !== null) {
            $this->info("Inference Geo (US) multiplier: {$geoMultiplier}x");
        }

        $this->newLine();
        $this->info('Last manual verification: 2026-04-12');
        $this->info('Cross-check: https://docs.claude.com/en/docs/about-claude/pricing');

        return self::SUCCESS;
    }
}
