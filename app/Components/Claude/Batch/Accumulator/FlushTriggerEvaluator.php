<?php

declare(strict_types=1);

namespace App\Components\Claude\Batch\Accumulator;

final class FlushTriggerEvaluator
{
    public function triggerCount(): int
    {
        return (int) config('llm.claude.batch.accumulator.trigger_count', 100);
    }

    public function triggerBytes(): int
    {
        return (int) config('llm.claude.batch.accumulator.trigger_bytes', 50 * 1024 * 1024);
    }

    public function triggerSeconds(): int
    {
        return (int) config('llm.claude.batch.accumulator.trigger_seconds', 300);
    }
}
