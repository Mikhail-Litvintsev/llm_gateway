<?php

namespace Tests\Unit\Components\PromptAssembler;

use App\Components\PromptAssembler\Formatters\OpenAiFormatter;
use PHPUnit\Framework\TestCase;

class OpenAiFormatterTest extends TestCase
{
    private OpenAiFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new OpenAiFormatter();
    }

    public function test_prepends_system_to_messages(): void
    {
        $result = $this->formatter->format(
            'You are helpful.',
            [['role' => 'user', 'content' => 'Hello']],
            null,
            [],
            'gpt-4o',
        );

        $this->assertCount(2, $result->body['messages']);
        $this->assertEquals('system', $result->body['messages'][0]['role']);
        $this->assertEquals('You are helpful.', $result->body['messages'][0]['content']);
        $this->assertEquals('user', $result->body['messages'][1]['role']);
    }

    public function test_omits_system_when_empty(): void
    {
        $result = $this->formatter->format(
            '',
            [['role' => 'user', 'content' => 'Hello']],
            null,
            [],
            'gpt-4o',
        );

        $this->assertCount(1, $result->body['messages']);
        $this->assertEquals('user', $result->body['messages'][0]['role']);
    }

    public function test_includes_tools(): void
    {
        $tools = [
            ['type' => 'function', 'function' => ['name' => 'tool1', 'parameters' => []]],
        ];

        $result = $this->formatter->format(
            '',
            [['role' => 'user', 'content' => 'Use tool']],
            $tools,
            [],
            'gpt-4o',
        );

        $this->assertArrayHasKey('tools', $result->body);
    }

    public function test_sets_model(): void
    {
        $result = $this->formatter->format(
            '',
            [['role' => 'user', 'content' => 'Hello']],
            null,
            [],
            'gpt-4o',
        );

        $this->assertEquals('gpt-4o', $result->body['model']);
    }
}
