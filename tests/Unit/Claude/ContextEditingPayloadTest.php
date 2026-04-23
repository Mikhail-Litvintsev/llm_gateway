<?php

declare(strict_types=1);

namespace Tests\Unit\Claude;

use App\Components\Claude\DTO\ContextManagementConfig;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ContextEditingPayloadTest extends TestCase
{
    #[Test]
    public function from_array_with_compaction(): void
    {
        $config = ContextManagementConfig::fromArray([
            'compaction' => ['trigger' => ['type' => 'input_tokens', 'value' => 150000]],
        ]);

        $this->assertNotNull($config->compaction);
        $this->assertSame('input_tokens', $config->compaction['trigger']['type']);
    }

    #[Test]
    public function from_array_null_returns_empty(): void
    {
        $config = ContextManagementConfig::fromArray(null);

        $this->assertTrue($config->isEmpty());
    }

    #[Test]
    public function from_array_empty_returns_empty(): void
    {
        $config = ContextManagementConfig::fromArray([]);

        $this->assertTrue($config->isEmpty());
    }

    #[Test]
    public function clear_tool_uses_and_clear_thinking_both_set(): void
    {
        $config = ContextManagementConfig::fromArray([
            'clear_tool_uses' => ['preserve_last' => 2],
            'clear_thinking' => ['preserve_last' => 1],
        ]);

        $this->assertNotNull($config->clearToolUses);
        $this->assertNotNull($config->clearThinking);
    }

    #[Test]
    public function is_empty_when_all_null(): void
    {
        $config = new ContextManagementConfig;

        $this->assertTrue($config->isEmpty());
    }
}
