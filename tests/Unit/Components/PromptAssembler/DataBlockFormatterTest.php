<?php

namespace Tests\Unit\Components\PromptAssembler;

use App\Components\PromptAssembler\DataBlockFormatter;
use App\Components\RequestPipeline\DTO\PromptBlock;
use PHPUnit\Framework\TestCase;

class DataBlockFormatterTest extends TestCase
{
    private DataBlockFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new DataBlockFormatter();
    }

    public function test_formats_data_block_with_id(): void
    {
        $block = new PromptBlock('data', 'user', 'my_data', null, null, null, null, null, false, 'some content');

        $result = $this->formatter->format($block, null);

        $this->assertStringContainsString('<my_data>', $result);
        $this->assertStringContainsString('some content', $result);
        $this->assertStringContainsString('</my_data>', $result);
    }

    public function test_formats_data_block_with_label(): void
    {
        $block = new PromptBlock('data', 'user', 'my_data', 'My Label', null, null, null, null, false, 'content');

        $result = $this->formatter->format($block, null);

        $this->assertStringContainsString('label="My Label"', $result);
    }

    public function test_formats_data_block_with_description(): void
    {
        $data = new PromptBlock('data', 'user', 'my_data', null, null, null, null, null, false, 'content');
        $desc = new PromptBlock('description', 'user', null, null, null, null, 'my_data', null, false, 'This is data description.');

        $result = $this->formatter->format($data, $desc);

        $this->assertStringContainsString('This is data description.', $result);
        $this->assertStringContainsString('content', $result);
    }

    public function test_auto_generates_id_when_null(): void
    {
        $block = new PromptBlock('data', 'user', null, null, null, null, null, null, false, 'no id');

        $result = $this->formatter->format($block, null);

        $this->assertStringContainsString('<data_', $result);
    }
}
