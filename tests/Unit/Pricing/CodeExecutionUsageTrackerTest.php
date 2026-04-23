<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Components\Pricing\CodeExecutionUsageTracker;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CodeExecutionUsageTrackerTest extends TestCase
{
    #[Test]
    public function pool_size_returns_configured_value(): void
    {
        config(['llm.pricing.code_execution.free_hours_per_month' => 2000.0]);

        $tracker = app(CodeExecutionUsageTracker::class);

        $this->assertSame(2000.0, $tracker->poolSize());
    }
}
