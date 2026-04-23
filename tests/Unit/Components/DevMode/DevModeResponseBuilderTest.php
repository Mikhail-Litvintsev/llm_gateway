<?php

namespace Tests\Unit\Components\DevMode;

use App\Components\DevMode\DevModeResponseBuilder;
use App\Components\ProviderGateway\DTO\ProviderResponse;
use App\Components\ProviderGateway\DTO\UsageInfo;
use App\Models\RequestLog;
use Tests\TestCase;

class DevModeResponseBuilderTest extends TestCase
{
    private DevModeResponseBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new DevModeResponseBuilder();
    }

    public function test_build_provider_response_returns_valid_dto(): void
    {
        $response = $this->builder->buildProviderResponse();

        $this->assertInstanceOf(ProviderResponse::class, $response);
        $this->assertEquals(config('llm.dev_mode.content'), $response->content);
        $this->assertEquals(config('llm.dev_mode.finish_reason'), $response->finishReason);
        $this->assertEquals([], $response->toolCalls);
        $this->assertNull($response->reasoning);
        $this->assertFalse($response->structuredOutputFallback);
        $this->assertInstanceOf(UsageInfo::class, $response->usage);
        $this->assertEquals(config('llm.dev_mode.input_tokens'), $response->usage->inputTokens);
        $this->assertEquals(config('llm.dev_mode.output_tokens'), $response->usage->outputTokens);
    }

    public function test_build_callback_payload_has_correct_structure(): void
    {
        $requestLog = new RequestLog();
        $requestLog->meta_data = ['request_id' => 'test-123'];

        $payload = $this->builder->buildCallbackPayload($requestLog, 150);

        $this->assertArrayHasKey('status', $payload);
        $this->assertArrayHasKey('meta', $payload);
        $this->assertArrayHasKey('provider', $payload);
        $this->assertArrayHasKey('result', $payload);
        $this->assertArrayHasKey('latency_ms', $payload);
        $this->assertArrayHasKey('structured_output_fallback', $payload);
        $this->assertEquals('ok', $payload['status']);
        $this->assertEquals(150, $payload['latency_ms']);
    }

    public function test_build_callback_payload_uses_stub_provider(): void
    {
        $requestLog = new RequestLog();
        $requestLog->meta_data = [];

        $payload = $this->builder->buildCallbackPayload($requestLog, 100);

        $this->assertEquals('stub', $payload['provider']['name']);
        $this->assertEquals('dev-mode-stub', $payload['provider']['model']);
        $this->assertFalse($payload['provider']['is_fallback']);
    }

    public function test_build_stream_chunks_returns_token_and_done(): void
    {
        $chunks = $this->builder->buildStreamChunks();

        $this->assertCount(2, $chunks);
        $this->assertEquals('token', $chunks[0]->type);
        $this->assertEquals(config('llm.dev_mode.content'), $chunks[0]->content);
        $this->assertEquals(0, $chunks[0]->index);
        $this->assertEquals('done', $chunks[1]->type);
        $this->assertNull($chunks[1]->content);
        $this->assertEquals(1, $chunks[1]->index);
        $this->assertEquals('end_turn', $chunks[1]->finishReason);
    }

    public function test_build_stream_chunks_done_has_usage(): void
    {
        $chunks = $this->builder->buildStreamChunks();

        $doneChunk = $chunks[1];
        $this->assertInstanceOf(UsageInfo::class, $doneChunk->usage);
        $this->assertEquals(config('llm.dev_mode.input_tokens'), $doneChunk->usage->inputTokens);
        $this->assertEquals(config('llm.dev_mode.output_tokens'), $doneChunk->usage->outputTokens);
    }
}
