<?php

declare(strict_types=1);

namespace App\Components\Validation\Rules;

use App\Components\Claude\ToolTypeCatalog;
use App\Components\Validation\DTO\ValidationError;

final class PtcContractRule
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function check(array $payload): ?ValidationError
    {
        $tools = $payload['tools'] ?? [];
        $hasPtcCaller = false;

        foreach ($tools as $tool) {
            $callers = $tool['allowed_callers'] ?? null;
            if (! is_array($callers)) {
                continue;
            }
            if (in_array(ToolTypeCatalog::CODE_EXECUTION, $callers, true)) {
                $hasPtcCaller = true;
                break;
            }
        }

        if (! $hasPtcCaller) {
            return null;
        }

        return $this->checkIncompatibilities($payload, $tools);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, mixed>  $tools
     */
    private function checkIncompatibilities(array $payload, array $tools): ?ValidationError
    {
        foreach ($tools as $tool) {
            if (($tool['strict'] ?? false) === true) {
                return new ValidationError('/tools', 'ptc_strict', 'PTC is incompatible with strict: true');
            }
        }

        $tcType = $payload['tool_choice']['type'] ?? $payload['tool_choice'] ?? null;
        if ($tcType === 'tool') {
            return new ValidationError('/tool_choice', 'ptc_forced_tool_choice', 'PTC is incompatible with forced tool_choice');
        }

        if (($payload['disable_parallel_tool_use'] ?? false) === true) {
            return new ValidationError('/disable_parallel_tool_use', 'ptc_disable_parallel', 'PTC is incompatible with disable_parallel_tool_use');
        }

        $hasMcp = array_any($tools, fn (mixed $t): bool => is_array($t) && str_starts_with($t['type'] ?? '', 'mcp'));
        if ($hasMcp) {
            return new ValidationError('/tools', 'ptc_mcp', 'PTC is incompatible with MCP tools');
        }

        return null;
    }
}
