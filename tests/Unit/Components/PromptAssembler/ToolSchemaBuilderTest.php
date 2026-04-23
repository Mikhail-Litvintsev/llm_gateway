<?php

namespace Tests\Unit\Components\PromptAssembler;

use App\Components\PromptAssembler\ToolSchemaBuilder;
use App\Components\RequestPipeline\DTO\ToolDefinition;
use App\Components\RequestPipeline\DTO\ToolParam;
use App\Components\RequestPipeline\DTO\ToolsConfig;
use PHPUnit\Framework\TestCase;

class ToolSchemaBuilderTest extends TestCase
{
    private ToolSchemaBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new ToolSchemaBuilder();
    }

    private function makeToolsConfig(): ToolsConfig
    {
        return new ToolsConfig('auto', [
            new ToolDefinition('get_weather', 'Get weather', [
                new ToolParam('city', 'string', true, 'City name', null, null, null, null, []),
                new ToolParam('unit', 'string', false, 'Unit', '["celsius","fahrenheit"]', null, null, null, []),
            ]),
        ]);
    }

    public function test_builds_claude_tool_schema(): void
    {
        $result = $this->builder->build($this->makeToolsConfig(), 'claude');

        $this->assertCount(1, $result);
        $this->assertEquals('get_weather', $result[0]['name']);
        $this->assertArrayHasKey('input_schema', $result[0]);
        $this->assertEquals('object', $result[0]['input_schema']['type']);
        $this->assertContains('city', $result[0]['input_schema']['required']);
    }

    public function test_builds_openai_tool_schema(): void
    {
        $result = $this->builder->build($this->makeToolsConfig(), 'openai');

        $this->assertCount(1, $result);
        $this->assertEquals('function', $result[0]['type']);
        $this->assertEquals('get_weather', $result[0]['function']['name']);
        $this->assertArrayHasKey('parameters', $result[0]['function']);
    }

    public function test_builds_gemini_tool_schema(): void
    {
        $result = $this->builder->build($this->makeToolsConfig(), 'gemini');

        $this->assertCount(1, $result);
        $this->assertEquals('get_weather', $result[0]['name']);
        $this->assertArrayHasKey('parameters', $result[0]);
    }

    public function test_tool_choice_claude(): void
    {
        $result = $this->builder->buildToolChoice($this->makeToolsConfig(), 'claude');

        $this->assertEquals(['type' => 'auto'], $result);
    }

    public function test_tool_choice_openai(): void
    {
        $result = $this->builder->buildToolChoice($this->makeToolsConfig(), 'openai');

        $this->assertEquals('auto', $result);
    }
}
