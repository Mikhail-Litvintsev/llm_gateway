<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude\Response;

use App\Components\Claude\DTO\UsageData;
use App\Components\Claude\Response\ResponseParser;
use JsonException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResponseParserTest extends TestCase
{
    private ResponseParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new ResponseParser;
    }

    #[Test]
    public function parse_success_decodes_body_and_extracts_usage(): void
    {
        $raw = json_encode([
            'id' => 'msg_123',
            'content' => [['type' => 'text', 'text' => 'Hello']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
        ]);

        $result = $this->parser->parseSuccess($raw);

        $this->assertSame('msg_123', $result['parsed']['id']);
        $this->assertSame(10, $result['usage']['input_tokens']);
        $this->assertSame(20, $result['usage']['output_tokens']);
    }

    #[Test]
    public function parse_success_returns_empty_usage_when_absent(): void
    {
        $raw = json_encode(['id' => 'msg_456', 'content' => []]);

        $result = $this->parser->parseSuccess($raw);

        $this->assertSame([], $result['usage']);
    }

    #[Test]
    public function parse_success_throws_on_invalid_json(): void
    {
        $this->expectException(JsonException::class);

        $this->parser->parseSuccess('{not valid json');
    }

    #[Test]
    public function extract_usage_data_maps_all_fields(): void
    {
        $usage = [
            'input_tokens' => 100,
            'output_tokens' => 200,
            'cache_creation_input_tokens_breakdown' => [
                ['ttl' => '5m', 'tokens' => 50],
                ['ttl' => '1h', 'tokens' => 30],
            ],
            'cache_read_input_tokens' => 15,
            'thinking_tokens' => 5,
            'server_tool_use' => [
                ['type' => 'web_search', 'count' => 2],
                ['type' => 'web_fetch', 'count' => 3],
                ['type' => 'code_execution', 'count' => 1],
                ['type' => 'tool_search', 'count' => 4],
            ],
        ];

        $data = $this->parser->extractUsageData($usage);

        $this->assertInstanceOf(UsageData::class, $data);
        $this->assertSame(100, $data->inputTokens);
        $this->assertSame(200, $data->outputTokens);
        $this->assertSame(50, $data->cacheCreation5mTokens);
        $this->assertSame(30, $data->cacheCreation1hTokens);
        $this->assertSame(15, $data->cacheReadTokens);
        $this->assertSame(5, $data->thinkingTokens);
        $this->assertSame(2, $data->serverToolWebSearchCount);
        $this->assertSame(3, $data->serverToolWebFetchCount);
        $this->assertSame(1, $data->serverToolCodeExecCount);
        $this->assertSame(4, $data->serverToolToolSearchCount);
    }

    #[Test]
    public function extract_usage_data_defaults_to_zeros(): void
    {
        $data = $this->parser->extractUsageData([]);

        $this->assertSame(0, $data->inputTokens);
        $this->assertSame(0, $data->outputTokens);
        $this->assertSame(0, $data->cacheCreation5mTokens);
        $this->assertSame(0, $data->cacheCreation1hTokens);
        $this->assertSame(0, $data->cacheReadTokens);
        $this->assertSame(0, $data->thinkingTokens);
        $this->assertSame(0, $data->serverToolWebSearchCount);
    }

    #[Test]
    public function extract_usage_data_falls_back_to_legacy_cache_creation_field(): void
    {
        $usage = [
            'input_tokens' => 50,
            'output_tokens' => 60,
            'cache_creation_input_tokens' => 42,
        ];

        $data = $this->parser->extractUsageData($usage);

        $this->assertSame(42, $data->cacheCreation5mTokens);
        $this->assertSame(0, $data->cacheCreation1hTokens);
    }

    #[Test]
    public function parse_message_response_extracts_rate_limit_headers(): void
    {
        $body = [
            'id' => 'msg_abc',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Hi']],
            'model' => 'claude-sonnet-4-6',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 2],
        ];

        $headers = [
            'request-id' => 'req_xyz',
            'anthropic-organization-id' => 'org_1',
            'Anthropic-Ratelimit-Requests-Remaining' => '99',
            'Anthropic-Ratelimit-Tokens-Remaining' => ['5000'],
        ];

        $response = $this->parser->parseMessageResponse($body, $headers);

        $this->assertSame('msg_abc', $response->anthropicId);
        $this->assertSame('req_xyz', $response->anthropicRequestId);
        $this->assertSame('org_1', $response->anthropicOrganizationId);
        $this->assertSame('99', $response->rateLimitHeaders['anthropic-ratelimit-requests-remaining']);
        $this->assertSame('5000', $response->rateLimitHeaders['anthropic-ratelimit-tokens-remaining']);
    }
}
