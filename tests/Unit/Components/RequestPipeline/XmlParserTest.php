<?php

namespace Tests\Unit\Components\RequestPipeline;

use App\Components\RequestPipeline\Exceptions\XmlParseException;
use App\Components\RequestPipeline\XmlParser;
use PHPUnit\Framework\TestCase;

class XmlParserTest extends TestCase
{
    private XmlParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new XmlParser();
    }

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__ . '/../../../Fixtures/xml/' . $name);
    }

    public function test_parses_minimal_valid_request(): void
    {
        $result = $this->parser->parse($this->fixture('valid_minimal.xml'));

        $this->assertEquals('3.0', $result->version);
        $this->assertEquals('req_001', $result->meta->requestId);
        $this->assertNull($result->provider);
        $this->assertNull($result->tools);
        $this->assertNull($result->parameters);
        $this->assertCount(1, $result->blocks);
        $this->assertEquals('instruction', $result->blocks[0]->type);
        $this->assertEquals('user', $result->blocks[0]->role);
        $this->assertEquals('Tell me a joke.', $result->blocks[0]->content);
        $this->assertEquals('https://example.com/callback', $result->callback->url);
    }

    public function test_parses_full_request_with_all_sections(): void
    {
        $result = $this->parser->parse($this->fixture('valid_full.xml'));

        $this->assertEquals('req_full_001', $result->meta->requestId);
        $this->assertEquals('sess_001', $result->meta->sessionId);
        $this->assertEquals(1, $result->meta->stepId);
        $this->assertEquals('unit-test', $result->meta->source);
        $this->assertEquals('user_42', $result->meta->userId);
        $this->assertEquals('high', $result->meta->priority);

        $this->assertNotNull($result->provider);
        $this->assertEquals('claude', $result->provider->name);
        $this->assertEquals('claude-sonnet-4-20250514', $result->provider->model);

        $this->assertCount(6, $result->blocks);
        $this->assertEquals('system', $result->blocks[0]->type);

        $this->assertNotNull($result->tools);
        $this->assertEquals('auto', $result->tools->toolChoice);
        $this->assertCount(1, $result->tools->tools);
        $this->assertEquals('get_weather', $result->tools->tools[0]->name);
        $this->assertCount(2, $result->tools->tools[0]->params);

        $this->assertNotNull($result->parameters);
        $this->assertEquals(0.7, $result->parameters->temperature);
        $this->assertEquals(2048, $result->parameters->maxTokens);
        $this->assertEquals(0.9, $result->parameters->topP);

        $this->assertEquals('POST', $result->callback->method);
        $this->assertEquals(120, $result->callback->timeout);
        $this->assertEquals(3, $result->callback->retry->maxAttempts);
        $this->assertEquals('exponential', $result->callback->retry->backoff);
        $this->assertEquals(2, $result->callback->retry->initialDelay);
    }

    public function test_parses_provider_with_recursive_fallback(): void
    {
        $result = $this->parser->parse($this->fixture('valid_full.xml'));

        $this->assertNotNull($result->provider->fallback);
        $this->assertEquals('openai', $result->provider->fallback->name);
        $this->assertEquals('gpt-4o', $result->provider->fallback->model);
    }

    public function test_parses_tools_with_nested_params(): void
    {
        $result = $this->parser->parse($this->fixture('valid_full.xml'));

        $params = $result->tools->tools[0]->params;
        $this->assertEquals('city', $params[0]->name);
        $this->assertEquals('string', $params[0]->type);
        $this->assertTrue($params[0]->required);
        $this->assertEquals('City name', $params[0]->description);

        $this->assertEquals('unit', $params[1]->name);
        $this->assertFalse($params[1]->required);
        $this->assertNotNull($params[1]->enum);
    }

    public function test_throws_on_malformed_xml(): void
    {
        $this->expectException(XmlParseException::class);

        $this->parser->parse($this->fixture('invalid_malformed.xml'));
    }

    public function test_preserves_cdata_content(): void
    {
        $result = $this->parser->parse($this->fixture('valid_full.xml'));

        $dataBlock = $result->blocks[3]; // The CSV data block
        $this->assertStringContains('month,revenue', $dataBlock->content);
        $this->assertStringContains('150000', $dataBlock->content);
    }

    public function test_parses_extra_meta_fields(): void
    {
        $result = $this->parser->parse($this->fixture('valid_full.xml'));

        $this->assertArrayHasKey('custom_field', $result->meta->extraFields);
        $this->assertEquals('custom_value', $result->meta->extraFields['custom_field']);
    }

    public function test_throws_on_missing_meta(): void
    {
        $this->expectException(XmlParseException::class);

        $this->parser->parse($this->fixture('invalid_no_meta.xml'));
    }

    public function test_throws_on_missing_callback(): void
    {
        $this->expectException(XmlParseException::class);

        $this->parser->parse($this->fixture('invalid_no_callback.xml'));
    }

    public function test_throws_on_missing_request_id(): void
    {
        $this->expectException(XmlParseException::class);

        $this->parser->parse($this->fixture('invalid_no_request_id.xml'));
    }

    public function test_throws_on_unsupported_version(): void
    {
        $xml = '<?xml version="1.0"?><llm_request version="2.0"><meta><request_id>r</request_id></meta><prompt><block type="data" role="user">x</block></prompt><callback><url>https://x.com/cb</url></callback></llm_request>';

        $this->expectException(XmlParseException::class);
        $this->parser->parse($xml);
    }

    public function test_throws_on_wrong_root_element(): void
    {
        $xml = '<?xml version="1.0"?><wrong_root version="3.0"><meta><request_id>r</request_id></meta></wrong_root>';

        $this->expectException(XmlParseException::class);
        $this->parser->parse($xml);
    }

    public function test_parses_history_with_tool_use(): void
    {
        $result = $this->parser->parse($this->fixture('valid_with_history.xml'));

        $historyBlocks = array_filter($result->blocks, fn ($b) => in_array($b->type, ['history', 'history_tool_result']));
        $this->assertCount(5, $historyBlocks);

        $toolResult = array_values(array_filter($result->blocks, fn ($b) => $b->type === 'history_tool_result'))[0];
        $this->assertEquals('tc_1', $toolResult->toolCallId);
        $this->assertEquals('4', $toolResult->content);
    }

    public function test_parses_image_block(): void
    {
        $result = $this->parser->parse($this->fixture('valid_with_image.xml'));

        $imageBlock = $result->blocks[1];
        $this->assertEquals('image', $imageBlock->type);
        $this->assertEquals('base64', $imageBlock->format);
        $this->assertEquals('image/png', $imageBlock->mediaType);
        $this->assertNotEmpty($imageBlock->content);
    }

    public function test_default_callback_values(): void
    {
        $result = $this->parser->parse($this->fixture('valid_minimal.xml'));

        $this->assertEquals('POST', $result->callback->method);
        $this->assertEquals(300, $result->callback->timeout);
        $this->assertEquals(3, $result->callback->retry->maxAttempts);
        $this->assertEquals('exponential', $result->callback->retry->backoff);
        $this->assertEquals(1, $result->callback->retry->initialDelay);
    }

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(str_contains($haystack, $needle), "Failed asserting that '$haystack' contains '$needle'.");
    }
}
