<?php

namespace Tests\Unit\Components\PromptAssembler;

use App\Components\PromptAssembler\Formatters\ClaudeFormatter;
use PHPUnit\Framework\TestCase;

class ClaudeFormatterTest extends TestCase
{
    private ClaudeFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new ClaudeFormatter();
    }

    public function test_formats_system_prompt_separately(): void
    {
        $result = $this->formatter->format(
            'You are helpful.',
            [['role' => 'user', 'content' => 'Hello']],
            null,
            ['max_tokens' => 1024],
            'claude-sonnet-4-20250514',
        );

        $this->assertEquals('You are helpful.', $result->body['system']);
        $this->assertEquals('claude-sonnet-4-20250514', $result->body['model']);
    }

    public function test_omits_system_when_empty(): void
    {
        $result = $this->formatter->format(
            '',
            [['role' => 'user', 'content' => 'Hello']],
            null,
            ['max_tokens' => 1024],
            'claude-sonnet-4-20250514',
        );

        $this->assertArrayNotHasKey('system', $result->body);
    }

    public function test_formats_tools_as_anthropic_schema(): void
    {
        $tools = [
            ['name' => 'tool1', 'description' => 'Test tool', 'input_schema' => ['type' => 'object', 'properties' => []]],
        ];

        $result = $this->formatter->format(
            '',
            [['role' => 'user', 'content' => 'Use tool']],
            $tools,
            ['max_tokens' => 1024],
            'claude-sonnet-4-20250514',
        );

        $this->assertArrayHasKey('tools', $result->body);
        $this->assertCount(1, $result->body['tools']);
    }

    public function test_adds_content_type_header(): void
    {
        $result = $this->formatter->format(
            '',
            [['role' => 'user', 'content' => 'Hello']],
            null,
            ['max_tokens' => 1024],
            'claude-sonnet-4-20250514',
        );

        $this->assertEquals('application/json', $result->headers['content-type']);
    }

    public function test_merges_parameters_into_body(): void
    {
        $result = $this->formatter->format(
            '',
            [['role' => 'user', 'content' => 'Hello']],
            null,
            ['max_tokens' => 2048, 'temperature' => 0.5],
            'claude-sonnet-4-20250514',
        );

        $this->assertEquals(2048, $result->body['max_tokens']);
        $this->assertEquals(0.5, $result->body['temperature']);
    }
}
