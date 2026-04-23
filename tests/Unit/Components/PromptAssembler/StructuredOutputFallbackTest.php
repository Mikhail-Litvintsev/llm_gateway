<?php

namespace Tests\Unit\Components\PromptAssembler;

use App\Components\PromptAssembler\StructuredOutputFallback;
use App\Components\RequestPipeline\DTO\ResponseFormatConfig;
use PHPUnit\Framework\TestCase;

class StructuredOutputFallbackTest extends TestCase
{
    private StructuredOutputFallback $fallback;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fallback = new StructuredOutputFallback();
    }

    public function test_injects_schema_into_system_prompt(): void
    {
        $format = new ResponseFormatConfig(
            'json_schema',
            'trade_recommendation',
            false,
            '{"type":"object","properties":{"action":{"type":"string"}}}'
        );

        $result = $this->fallback->injectSchemaIntoSystemPrompt('You are a helpful assistant.', $format);

        $this->assertStringContainsString('You are a helpful assistant.', $result);
        $this->assertStringContainsString('RESPONSE FORMAT REQUIREMENT:', $result);
        $this->assertStringContainsString('Schema name: trade_recommendation', $result);
        $this->assertStringContainsString('"type": "object"', $result);
        $this->assertStringNotContainsString('STRICT MODE:', $result);
    }

    public function test_injects_strict_mode_instruction(): void
    {
        $format = new ResponseFormatConfig(
            'json_schema',
            'test_schema',
            true,
            '{"type":"object","properties":{"value":{"type":"number"}}}'
        );

        $result = $this->fallback->injectSchemaIntoSystemPrompt('System prompt.', $format);

        $this->assertStringContainsString('STRICT MODE:', $result);
        $this->assertStringContainsString('No additional properties are allowed', $result);
        $this->assertStringContainsString('All required fields MUST be present', $result);
    }
}
