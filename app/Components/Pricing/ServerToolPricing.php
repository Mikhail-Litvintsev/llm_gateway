<?php

declare(strict_types=1);

namespace App\Components\Pricing;

use App\Components\Claude\ToolTypeCatalog;

final readonly class ServerToolPricing
{
    public function priceServerTools(
        array $counts,
        array $requestServerToolTypes,
        int $workspaceId,
        float $codeExecutionHoursUsed,
        CodeExecutionUsageTracker $codeExecTracker,
    ): array {
        $webSearchCost = ($counts['web_search'] ?? 0) * (float) config('llm.pricing.server_tools.web_search_per_1k', 10.0) / 1000.0;

        $codeExecutionCost = 0.0;
        $freeHoursRemaining = $codeExecTracker->poolSize();

        if ($codeExecutionHoursUsed > 0) {
            if (!ToolTypeCatalog::codeExecutionIsFree($requestServerToolTypes)) {
                $consumption = $codeExecTracker->consume($workspaceId, $codeExecutionHoursUsed);
                $codeExecutionCost = $consumption->billedHours * (float) config('llm.pricing.code_execution.paid_per_hour', 0.05);
                $freeHoursRemaining = $consumption->freeHoursRemainingAfter;
            }
        }

        return [
            'web_search_usd' => $webSearchCost,
            'code_execution_usd' => $codeExecutionCost,
            'code_execution_free_hours_remaining' => $freeHoursRemaining,
        ];
    }
}
