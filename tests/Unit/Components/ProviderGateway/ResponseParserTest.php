<?php

namespace Tests\Unit\Components\ProviderGateway;

use App\Components\ProviderGateway\DTO\RawProviderResponse;
use App\Components\ProviderGateway\DTO\ResolvedProvider;
use App\Components\ProviderGateway\Exceptions\ProviderRateLimitedException;
use App\Components\ProviderGateway\Exceptions\ProviderTimeoutException;
use App\Components\ProviderGateway\Exceptions\ProviderUnavailableException;
use App\Components\ProviderGateway\ResponseParser;
use PHPUnit\Framework\TestCase;

class ResponseParserTest extends TestCase
{
    private ResponseParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ResponseParser();
    }

    private function fixtureJson(string $name): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../../../Fixtures/responses/' . $name), true);
    }

    private function provider(string $name): ResolvedProvider
    {
        return new ResolvedProvider($name, 'test-model', 'https://api.example.com', 'key');
    }

    public function test_parses_claude_text_response(): void
    {
        $raw = new RawProviderResponse(200, $this->fixtureJson('claude_success.json'), [], 100);

        $result = $this->parser->parse($raw, $this->provider('claude'));

        $this->assertEquals('Hello! How can I help you today?', $result->content);
        $this->assertEmpty($result->toolCalls);
        $this->assertEquals('end_turn', $result->finishReason);
        $this->assertEquals(25, $result->usage->inputTokens);
        $this->assertEquals(15, $result->usage->outputTokens);
        $this->assertNull($result->reasoning);
    }

    public function test_parses_claude_tool_use_response(): void
    {
        $raw = new RawProviderResponse(200, $this->fixtureJson('claude_tool_use.json'), [], 150);

        $result = $this->parser->parse($raw, $this->provider('claude'));

        $this->assertEquals('Let me check the weather for you.', $result->content);
        $this->assertCount(1, $result->toolCalls);
        $this->assertEquals('toolu_01A', $result->toolCalls[0]->id);
        $this->assertEquals('get_weather', $result->toolCalls[0]->name);
        $this->assertEquals(['city' => 'London', 'unit' => 'celsius'], $result->toolCalls[0]->arguments);
        $this->assertEquals('tool_use', $result->finishReason);
    }

    public function test_parses_claude_thinking_response(): void
    {
        $body = [
            'content' => [
                ['type' => 'thinking', 'thinking' => 'Let me think about this...'],
                ['type' => 'text', 'text' => 'The answer is 42.'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
        ];
        $raw = new RawProviderResponse(200, $body, [], 200);

        $result = $this->parser->parse($raw, $this->provider('claude'));

        $this->assertEquals('The answer is 42.', $result->content);
        $this->assertNotNull($result->reasoning);
        $this->assertEquals('Let me think about this...', $result->reasoning['content']);
    }

    public function test_parses_openai_response(): void
    {
        $raw = new RawProviderResponse(200, $this->fixtureJson('openai_success.json'), [], 100);

        $result = $this->parser->parse($raw, $this->provider('openai'));

        $this->assertEquals('Hello! How can I assist you?', $result->content);
        $this->assertEmpty($result->toolCalls);
        $this->assertEquals('end_turn', $result->finishReason);
        $this->assertEquals(20, $result->usage->inputTokens);
        $this->assertEquals(10, $result->usage->outputTokens);
    }

    public function test_parses_openai_tool_calls(): void
    {
        $raw = new RawProviderResponse(200, $this->fixtureJson('openai_tool_calls.json'), [], 100);

        $result = $this->parser->parse($raw, $this->provider('openai'));

        $this->assertNull($result->content);
        $this->assertCount(1, $result->toolCalls);
        $this->assertEquals('call_abc', $result->toolCalls[0]->id);
        $this->assertEquals('get_weather', $result->toolCalls[0]->name);
        $this->assertEquals('tool_use', $result->finishReason);
    }

    public function test_parses_deepseek_response(): void
    {
        $raw = new RawProviderResponse(200, $this->fixtureJson('openai_success.json'), [], 100);

        $result = $this->parser->parse($raw, $this->provider('deepseek'));

        $this->assertEquals('Hello! How can I assist you?', $result->content);
        $this->assertEquals('end_turn', $result->finishReason);
    }

    public function test_parses_gemini_response(): void
    {
        $body = [
            'candidates' => [
                [
                    'content' => ['parts' => [['text' => 'Hello from Gemini!']]],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => ['promptTokenCount' => 15, 'candidatesTokenCount' => 8],
        ];
        $raw = new RawProviderResponse(200, $body, [], 100);

        $result = $this->parser->parse($raw, $this->provider('gemini'));

        $this->assertEquals('Hello from Gemini!', $result->content);
        $this->assertEquals('end_turn', $result->finishReason);
        $this->assertEquals(15, $result->usage->inputTokens);
        $this->assertEquals(8, $result->usage->outputTokens);
    }

    public function test_throws_on_rate_limit(): void
    {
        $raw = new RawProviderResponse(429, ['error' => ['message' => 'Rate limited']], [], 100);

        $this->expectException(ProviderRateLimitedException::class);

        $this->parser->parse($raw, $this->provider('claude'));
    }

    public function test_rate_limited_exception_contains_retry_after(): void
    {
        $raw = new RawProviderResponse(
            429,
            ['error' => ['message' => 'Rate limited']],
            ['retry-after' => ['30']],
            100,
        );

        try {
            $this->parser->parse($raw, $this->provider('claude'));
            $this->fail('Expected ProviderRateLimitedException');
        } catch (ProviderRateLimitedException $e) {
            $this->assertEquals(30, $e->retryAfter);
        }
    }

    public function test_throws_on_server_error(): void
    {
        $raw = new RawProviderResponse(503, $this->fixtureJson('claude_error_503.json'), [], 100);

        $this->expectException(ProviderUnavailableException::class);

        $this->parser->parse($raw, $this->provider('claude'));
    }

    public function test_throws_on_timeout(): void
    {
        $raw = new RawProviderResponse(0, [], [], 0);

        $this->expectException(ProviderTimeoutException::class);

        $this->parser->parse($raw, $this->provider('claude'));
    }

    public function test_structured_output_fallback_flag_propagated(): void
    {
        $body = [
            'content' => [['type' => 'text', 'text' => '{"action":"LONG"}']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ];
        $raw = new RawProviderResponse(200, $body, [], 100);

        $resultWithFallback = $this->parser->parse($raw, $this->provider('claude'), true);
        $this->assertTrue($resultWithFallback->structuredOutputFallback);

        $resultWithout = $this->parser->parse($raw, $this->provider('claude'), false);
        $this->assertFalse($resultWithout->structuredOutputFallback);
    }

    public function test_structured_output_fallback_flag_propagated_openai(): void
    {
        $raw = new RawProviderResponse(200, $this->fixtureJson('openai_success.json'), [], 100);

        $result = $this->parser->parse($raw, $this->provider('openai'), true);
        $this->assertTrue($result->structuredOutputFallback);
    }

    public function test_structured_output_fallback_flag_propagated_gemini(): void
    {
        $body = [
            'candidates' => [
                [
                    'content' => ['parts' => [['text' => '{"value":42}']]],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ];
        $raw = new RawProviderResponse(200, $body, [], 100);

        $result = $this->parser->parse($raw, $this->provider('gemini'), true);
        $this->assertTrue($result->structuredOutputFallback);
    }
}
