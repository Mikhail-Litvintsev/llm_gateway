<?php

namespace Tests\Unit\Components\PromptAssembler;

use App\Components\PromptAssembler\ParameterMapper;
use App\Components\RequestPipeline\DTO\GenerationParameters;
use App\Components\RequestPipeline\DTO\ReasoningConfig;
use App\Components\RequestPipeline\DTO\ResponseFormatConfig;
use Tests\TestCase;

class ParameterMapperTest extends TestCase
{
    private ParameterMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new ParameterMapper();

        config(['llm.providers.claude.default_max_tokens' => 4096]);
    }

    public function test_maps_claude_parameters(): void
    {
        $params = new GenerationParameters(0.7, 2048, 0.9, 10, ['STOP'], null, false, null, []);

        $result = $this->mapper->map($params, 'claude');

        $this->assertEquals(0.7, $result['temperature']);
        $this->assertEquals(2048, $result['max_tokens']);
        $this->assertEquals(0.9, $result['top_p']);
        $this->assertEquals(10, $result['top_k']);
        $this->assertEquals(['STOP'], $result['stop_sequences']);
    }

    public function test_maps_claude_reasoning_overrides_temperature(): void
    {
        $reasoning = new ReasoningConfig(true, 'medium', 1000);
        $params = new GenerationParameters(0.5, 4096, null, null, null, null, false, $reasoning, []);

        $result = $this->mapper->map($params, 'claude');

        $this->assertEquals(1.0, $result['temperature']);
        $this->assertArrayHasKey('thinking', $result);
        $this->assertEquals('enabled', $result['thinking']['type']);
    }

    public function test_maps_openai_parameters(): void
    {
        $params = new GenerationParameters(0.8, 1024, 0.95, null, ['END'], null, false, null, []);

        $result = $this->mapper->map($params, 'openai');

        $this->assertEquals(0.8, $result['temperature']);
        $this->assertEquals(1024, $result['max_tokens']);
        $this->assertEquals(0.95, $result['top_p']);
        $this->assertEquals(['END'], $result['stop']);
    }

    public function test_maps_gemini_parameters(): void
    {
        $params = new GenerationParameters(0.5, 512, null, 40, null, null, false, null, []);

        $result = $this->mapper->map($params, 'gemini');

        $this->assertEquals(0.5, $result['generationConfig']['temperature']);
        $this->assertEquals(512, $result['generationConfig']['maxOutputTokens']);
        $this->assertEquals(40, $result['generationConfig']['topK']);
    }

    public function test_passes_extra_parameters(): void
    {
        $params = new GenerationParameters(null, null, null, null, null, null, false, null, ['custom_param' => 'value']);

        $result = $this->mapper->map($params, 'claude');

        $this->assertEquals('value', $result['custom_param']);
    }

    public function test_claude_defaults_max_tokens(): void
    {
        $params = new GenerationParameters(null, null, null, null, null, null, false, null, []);

        $result = $this->mapper->map($params, 'claude');

        $this->assertEquals(4096, $result['max_tokens']);
    }

    public function test_maps_claude_json_schema_to_output_config(): void
    {
        $format = new ResponseFormatConfig('json_schema', 'test', true, '{"type":"object","properties":{"action":{"type":"string"}}}');
        $params = new GenerationParameters(null, null, null, null, null, $format, false, null, []);

        $result = $this->mapper->map($params, 'claude');

        $this->assertArrayHasKey('output_config', $result);
        $this->assertEquals('json_schema', $result['output_config']['format']['type']);
        $this->assertEquals('object', $result['output_config']['format']['schema']['type']);
        $this->assertArrayNotHasKey('response_format', $result);
    }

    public function test_claude_ignores_json_object_without_schema(): void
    {
        $format = new ResponseFormatConfig('json_object', null, null, null);
        $params = new GenerationParameters(null, null, null, null, null, $format, false, null, []);

        $result = $this->mapper->map($params, 'claude');

        $this->assertArrayNotHasKey('output_config', $result);
    }

    public function test_maps_gemini_json_schema_response_format(): void
    {
        $format = new ResponseFormatConfig('json_schema', 'test', true, '{"type":"object","properties":{"value":{"type":"number"}}}');
        $params = new GenerationParameters(null, null, null, null, null, $format, false, null, []);

        $result = $this->mapper->map($params, 'gemini');

        $this->assertEquals('application/json', $result['generationConfig']['responseMimeType']);
        $this->assertArrayHasKey('responseSchema', $result['generationConfig']);
        $this->assertEquals('object', $result['generationConfig']['responseSchema']['type']);
    }

    public function test_gemini_removes_additional_properties_from_schema(): void
    {
        $schema = json_encode([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'address' => [
                    'type' => 'object',
                    'properties' => ['city' => ['type' => 'string']],
                    'additionalProperties' => false,
                ],
            ],
            'additionalProperties' => false,
        ]);
        $format = new ResponseFormatConfig('json_schema', 'test', true, $schema);
        $params = new GenerationParameters(null, null, null, null, null, $format, false, null, []);

        $result = $this->mapper->map($params, 'gemini');

        $responseSchema = $result['generationConfig']['responseSchema'];
        $this->assertArrayNotHasKey('additionalProperties', $responseSchema);
        $this->assertArrayNotHasKey('additionalProperties', $responseSchema['properties']['address']);
    }

    public function test_deepseek_downgrades_json_schema_to_json_object(): void
    {
        $format = new ResponseFormatConfig('json_schema', 'test', true, '{"type":"object"}');
        $params = new GenerationParameters(null, null, null, null, null, $format, false, null, []);

        $result = $this->mapper->map($params, 'deepseek');

        $this->assertEquals(['type' => 'json_object'], $result['response_format']);
    }

    public function test_maps_openai_json_schema_response_format(): void
    {
        $format = new ResponseFormatConfig('json_schema', 'test_schema', true, '{"type":"object","properties":{"value":{"type":"number"}}}');
        $params = new GenerationParameters(null, null, null, null, null, $format, false, null, []);

        $result = $this->mapper->map($params, 'openai');

        $this->assertEquals('json_schema', $result['response_format']['type']);
        $this->assertEquals('test_schema', $result['response_format']['json_schema']['name']);
        $this->assertTrue($result['response_format']['json_schema']['strict']);
    }
}
