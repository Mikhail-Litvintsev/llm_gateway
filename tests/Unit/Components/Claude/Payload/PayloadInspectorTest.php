<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude\Payload;

use App\Components\Claude\Payload\PayloadInspector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PayloadInspectorTest extends TestCase
{
    private PayloadInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inspector = new PayloadInspector;
    }

    #[Test]
    public function returns_false_for_empty_payload(): void
    {
        $this->assertFalse($this->inspector->hasCacheControl([]));
    }

    #[Test]
    public function returns_true_for_top_level_cache_control(): void
    {
        $this->assertTrue($this->inspector->hasCacheControl(['cache_control' => ['type' => 'ephemeral']]));
    }

    #[Test]
    public function returns_true_for_system_block_cache_control(): void
    {
        $this->assertTrue($this->inspector->hasCacheControl([
            'system' => [
                ['type' => 'text', 'text' => 'sys', 'cache_control' => ['type' => 'ephemeral']],
            ],
        ]));
    }

    #[Test]
    public function returns_true_for_message_content_block_cache_control(): void
    {
        $this->assertTrue($this->inspector->hasCacheControl([
            'messages' => [
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => 'hello', 'cache_control' => ['type' => 'ephemeral']],
                ]],
            ],
        ]));
    }

    #[Test]
    public function returns_false_when_content_is_string_not_array(): void
    {
        $this->assertFalse($this->inspector->hasCacheControl([
            'messages' => [
                ['role' => 'user', 'content' => 'plain text message'],
            ],
        ]));
    }

    #[Test]
    public function returns_false_when_system_is_empty(): void
    {
        $this->assertFalse($this->inspector->hasCacheControl(['system' => []]));
    }

    #[Test]
    public function returns_false_when_cache_control_is_null(): void
    {
        $this->assertFalse($this->inspector->hasCacheControl(['cache_control' => null]));
    }
}
