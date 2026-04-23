<?php

namespace Tests\Feature\Jobs;

use App\Components\CallbackDelivery\StreamingDelivery;
use App\Components\ProviderGateway\Streaming\StreamChunk;
use App\Components\RequestPipeline\Enums\RequestStatus;
use App\Jobs\ProcessLlmStreamRequest;
use App\Models\ApiClient;
use App\Models\CallbackUrl;
use App\Models\PendingPrompt;
use App\Models\RequestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProcessLlmStreamRequestDevModeTest extends TestCase
{
    use RefreshDatabase;

    private function createDevModeSetup(): array
    {
        $client = ApiClient::factory()->create([
            'signing_secret' => 'lgs_test_secret',
            'dev_mode' => true,
        ]);

        CallbackUrl::factory()->create(['api_client_id' => $client->id]);

        $requestLog = RequestLog::factory()->create([
            'api_client_id' => $client->id,
            'request_id' => 'req_stream_dev_001',
            'callback_url' => 'https://example.com/callback',
            'meta_data' => ['request_id' => 'req_stream_dev_001'],
            'status' => RequestStatus::Accepted,
            'stream' => true,
        ]);

        PendingPrompt::create([
            'request_log_id' => $requestLog->id,
            'prompt_xml' => '<prompt><block type="instruction" role="user">Test prompt</block></prompt>',
            'expires_at' => now()->addDays(3),
        ]);

        return [$client, $requestLog];
    }

    public function test_stream_dev_mode_sends_sse_events(): void
    {
        config(['llm.dev_mode.latency_ms' => 0]);

        [$client, $requestLog] = $this->createDevModeSetup();

        $mockDelivery = Mockery::mock(StreamingDelivery::class);
        $mockDelivery->shouldReceive('sendEvent')
            ->twice()
            ->andReturn(true);

        $this->app->instance(StreamingDelivery::class, $mockDelivery);

        $job = new ProcessLlmStreamRequest($requestLog->id);
        $job->handle(
            app(\App\Components\RequestPipeline\XmlParser::class),
            app(\App\Components\PromptAssembler\PromptAssembler::class),
            app(\App\Components\ProviderGateway\ProviderGateway::class),
            app(\App\Components\ProviderGateway\Streaming\StreamHandler::class),
            $mockDelivery,
            app(\App\Components\ProviderGateway\Streaming\ProviderStreamReader::class),
            app(\App\Components\RateLimiter\RequestThrottle::class),
            app(\App\Components\RateLimiter\Claude\ClaudeTokenEstimator::class),
            app(\App\Components\RateLimiter\Claude\ClaudeTokenBudget::class),
        );

        $mockDelivery->shouldHaveReceived('sendEvent')->twice();
    }

    public function test_stream_dev_mode_creates_response_log(): void
    {
        config(['llm.dev_mode.latency_ms' => 0]);

        [$client, $requestLog] = $this->createDevModeSetup();

        $mockDelivery = Mockery::mock(StreamingDelivery::class);
        $mockDelivery->shouldReceive('sendEvent')->andReturn(true);
        $this->app->instance(StreamingDelivery::class, $mockDelivery);

        $job = new ProcessLlmStreamRequest($requestLog->id);
        $job->handle(
            app(\App\Components\RequestPipeline\XmlParser::class),
            app(\App\Components\PromptAssembler\PromptAssembler::class),
            app(\App\Components\ProviderGateway\ProviderGateway::class),
            app(\App\Components\ProviderGateway\Streaming\StreamHandler::class),
            $mockDelivery,
            app(\App\Components\ProviderGateway\Streaming\ProviderStreamReader::class),
            app(\App\Components\RateLimiter\RequestThrottle::class),
            app(\App\Components\RateLimiter\Claude\ClaudeTokenEstimator::class),
            app(\App\Components\RateLimiter\Claude\ClaudeTokenBudget::class),
        );

        $this->assertDatabaseHas('response_log', [
            'request_log_id' => $requestLog->id,
            'provider_used' => 'stub',
            'model_used' => 'dev-mode-stub',
            'status' => 'ok',
        ]);
    }

    public function test_stream_dev_mode_updates_request_log(): void
    {
        config(['llm.dev_mode.latency_ms' => 0]);

        [$client, $requestLog] = $this->createDevModeSetup();

        $mockDelivery = Mockery::mock(StreamingDelivery::class);
        $mockDelivery->shouldReceive('sendEvent')->andReturn(true);
        $this->app->instance(StreamingDelivery::class, $mockDelivery);

        $job = new ProcessLlmStreamRequest($requestLog->id);
        $job->handle(
            app(\App\Components\RequestPipeline\XmlParser::class),
            app(\App\Components\PromptAssembler\PromptAssembler::class),
            app(\App\Components\ProviderGateway\ProviderGateway::class),
            app(\App\Components\ProviderGateway\Streaming\StreamHandler::class),
            $mockDelivery,
            app(\App\Components\ProviderGateway\Streaming\ProviderStreamReader::class),
            app(\App\Components\RateLimiter\RequestThrottle::class),
            app(\App\Components\RateLimiter\Claude\ClaudeTokenEstimator::class),
            app(\App\Components\RateLimiter\Claude\ClaudeTokenBudget::class),
        );

        $requestLog->refresh();
        $this->assertEquals(RequestStatus::Completed, $requestLog->status);
        $this->assertEquals('stub', $requestLog->provider_used);
        $this->assertEquals('dev-mode-stub', $requestLog->model_used);
    }
}
