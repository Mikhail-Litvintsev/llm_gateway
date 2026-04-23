<?php

namespace Tests\Unit\Components\PromptAssembler;

use App\Components\PromptAssembler\Enums\StructuredOutputSupport;
use App\Components\PromptAssembler\StructuredOutputResolver;
use App\Components\RequestPipeline\DTO\ResponseFormatConfig;
use PHPUnit\Framework\TestCase;

class StructuredOutputResolverTest extends TestCase
{
    private StructuredOutputResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new StructuredOutputResolver();
    }

    public function test_resolves_support_level_for_each_provider(): void
    {
        $this->assertEquals(StructuredOutputSupport::Native, $this->resolver->resolveSupport('openai'));
        $this->assertEquals(StructuredOutputSupport::Native, $this->resolver->resolveSupport('claude'));
        $this->assertEquals(StructuredOutputSupport::Native, $this->resolver->resolveSupport('gemini'));
        $this->assertEquals(StructuredOutputSupport::Native, $this->resolver->resolveSupport('mistral'));
        $this->assertEquals(StructuredOutputSupport::JsonObjectOnly, $this->resolver->resolveSupport('deepseek'));
        $this->assertEquals(StructuredOutputSupport::None, $this->resolver->resolveSupport('unknown'));
    }

    public function test_no_fallback_for_claude_json_schema(): void
    {
        $format = new ResponseFormatConfig('json_schema', 'test', true, '{"type":"object"}');
        $this->assertFalse($this->resolver->needsFallbackEmulation('claude', $format));
    }

    public function test_no_fallback_for_openai_json_schema(): void
    {
        $format = new ResponseFormatConfig('json_schema', 'test', true, '{"type":"object"}');
        $this->assertFalse($this->resolver->needsFallbackEmulation('openai', $format));
    }

    public function test_needs_fallback_for_deepseek_json_schema(): void
    {
        $format = new ResponseFormatConfig('json_schema', 'test', true, '{"type":"object"}');
        $this->assertTrue($this->resolver->needsFallbackEmulation('deepseek', $format));
    }

    public function test_no_fallback_for_deepseek_json_object(): void
    {
        $format = new ResponseFormatConfig('json_object', null, null, null);
        $this->assertFalse($this->resolver->needsFallbackEmulation('deepseek', $format));
    }

    public function test_no_fallback_for_text_type(): void
    {
        $format = new ResponseFormatConfig('text', null, null, null);
        $this->assertFalse($this->resolver->needsFallbackEmulation('deepseek', $format));
    }
}
