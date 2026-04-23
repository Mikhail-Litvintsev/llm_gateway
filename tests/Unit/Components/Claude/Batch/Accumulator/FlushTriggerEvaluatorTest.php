<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude\Batch\Accumulator;

use App\Components\Claude\Batch\Accumulator\FlushTriggerEvaluator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('phase3-unit')]
final class FlushTriggerEvaluatorTest extends TestCase
{
    private FlushTriggerEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'llm.claude.batch.accumulator.trigger_count' => 100,
            'llm.claude.batch.accumulator.trigger_bytes' => 50 * 1024 * 1024,
            'llm.claude.batch.accumulator.trigger_seconds' => 300,
        ]);

        $this->evaluator = new FlushTriggerEvaluator;
    }

    #[Test]
    public function trigger_count_returns_configured_value(): void
    {
        $this->assertSame(100, $this->evaluator->triggerCount());
    }

    #[Test]
    public function trigger_bytes_returns_configured_value(): void
    {
        $this->assertSame(50 * 1024 * 1024, $this->evaluator->triggerBytes());
    }

    #[Test]
    public function trigger_seconds_returns_configured_value(): void
    {
        $this->assertSame(300, $this->evaluator->triggerSeconds());
    }

    #[Test]
    public function custom_config_values_reflected(): void
    {
        config([
            'llm.claude.batch.accumulator.trigger_count' => 50,
            'llm.claude.batch.accumulator.trigger_bytes' => 10 * 1024 * 1024,
            'llm.claude.batch.accumulator.trigger_seconds' => 60,
        ]);

        $evaluator = new FlushTriggerEvaluator;

        $this->assertSame(50, $evaluator->triggerCount());
        $this->assertSame(10 * 1024 * 1024, $evaluator->triggerBytes());
        $this->assertSame(60, $evaluator->triggerSeconds());
    }

    #[Test]
    public function defaults_used_when_config_missing(): void
    {
        $app = $this->app;
        $app['config']->set('llm.claude.batch.accumulator', null);

        $evaluator = new FlushTriggerEvaluator;

        $this->assertSame(100, $evaluator->triggerCount());
        $this->assertSame(50 * 1024 * 1024, $evaluator->triggerBytes());
        $this->assertSame(300, $evaluator->triggerSeconds());
    }
}
