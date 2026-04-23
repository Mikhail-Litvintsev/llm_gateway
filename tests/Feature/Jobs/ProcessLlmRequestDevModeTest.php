<?php

namespace Tests\Feature\Jobs;

use App\Components\RequestPipeline\Enums\RequestStatus;
use App\Jobs\DeliverCallback;
use App\Jobs\ProcessLlmRequest;
use App\Models\ApiClient;
use App\Models\CallbackUrl;
use App\Models\PendingPrompt;
use App\Models\RequestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessLlmRequestDevModeTest extends TestCase
{
    use RefreshDatabase;

    private function createDevModeSetup(bool $devMode = true): array
    {
        $client = ApiClient::factory()->create([
            'signing_secret' => 'lgs_test_secret',
            'dev_mode' => $devMode,
        ]);

        CallbackUrl::factory()->create(['api_client_id' => $client->id]);

        $requestLog = RequestLog::factory()->create([
            'api_client_id' => $client->id,
            'request_id' => 'req_dev_001',
            'callback_url' => 'https://example.com/callback',
            'meta_data' => ['request_id' => 'req_dev_001'],
            'status' => RequestStatus::Accepted,
        ]);

        PendingPrompt::create([
            'request_log_id' => $requestLog->id,
            'prompt_xml' => '<prompt><block type="instruction" role="user">Test prompt</block></prompt>',
            'expires_at' => now()->addDays(3),
        ]);

        return [$client, $requestLog];
    }

    private function executeJob(int $requestLogId): void
    {
        $job = new ProcessLlmRequest($requestLogId);
        $job->handle(
            app(\App\Components\RequestPipeline\XmlParser::class),
            app(\App\Components\PromptAssembler\PromptAssembler::class),
            app(\App\Components\ProviderGateway\ProviderGateway::class),
            app(\App\Components\ProviderGateway\ResponseParser::class),
            app(\App\Components\RateLimiter\RequestThrottle::class),
            app(\App\Components\RateLimiter\Claude\ClaudeTokenEstimator::class),
            app(\App\Components\RateLimiter\Claude\ClaudeTokenBudget::class),
        );
    }

    public function test_dev_mode_skips_provider_call(): void
    {
        Http::fake();

        [$client, $requestLog] = $this->createDevModeSetup(true);

        Queue::fake([DeliverCallback::class]);
        config(['llm.dev_mode.latency_ms' => 0]);

        $this->executeJob($requestLog->id);

        Http::assertNothingSent();
    }

    public function test_dev_mode_creates_response_log(): void
    {
        Queue::fake([DeliverCallback::class]);
        config(['llm.dev_mode.latency_ms' => 0]);

        [$client, $requestLog] = $this->createDevModeSetup(true);

        $this->executeJob($requestLog->id);

        $this->assertDatabaseHas('response_log', [
            'request_log_id' => $requestLog->id,
            'provider_used' => 'stub',
            'model_used' => 'dev-mode-stub',
            'status' => 'ok',
        ]);
    }

    public function test_dev_mode_creates_pending_response(): void
    {
        Queue::fake([DeliverCallback::class]);
        config(['llm.dev_mode.latency_ms' => 0]);

        [$client, $requestLog] = $this->createDevModeSetup(true);

        $this->executeJob($requestLog->id);

        $this->assertDatabaseHas('pending_responses', [
            'request_log_id' => $requestLog->id,
            'callback_url' => 'https://example.com/callback',
            'delivery_status' => 'pending',
        ]);
    }

    public function test_dev_mode_dispatches_deliver_callback(): void
    {
        Queue::fake([DeliverCallback::class]);
        config(['llm.dev_mode.latency_ms' => 0]);

        [$client, $requestLog] = $this->createDevModeSetup(true);

        $this->executeJob($requestLog->id);

        Queue::assertPushed(DeliverCallback::class);
    }

    public function test_dev_mode_deletes_pending_prompt(): void
    {
        Queue::fake([DeliverCallback::class]);
        config(['llm.dev_mode.latency_ms' => 0]);

        [$client, $requestLog] = $this->createDevModeSetup(true);

        $this->executeJob($requestLog->id);

        $this->assertDatabaseMissing('pending_prompts', [
            'request_log_id' => $requestLog->id,
        ]);
    }

    public function test_dev_mode_updates_request_log_status_completed(): void
    {
        Queue::fake([DeliverCallback::class]);
        config(['llm.dev_mode.latency_ms' => 0]);

        [$client, $requestLog] = $this->createDevModeSetup(true);

        $this->executeJob($requestLog->id);

        $requestLog->refresh();
        $this->assertEquals(RequestStatus::Completed, $requestLog->status);
    }

    public function test_dev_mode_request_log_has_stub_provider(): void
    {
        Queue::fake([DeliverCallback::class]);
        config(['llm.dev_mode.latency_ms' => 0]);

        [$client, $requestLog] = $this->createDevModeSetup(true);

        $this->executeJob($requestLog->id);

        $requestLog->refresh();
        $this->assertEquals('stub', $requestLog->provider_used);
        $this->assertEquals('dev-mode-stub', $requestLog->model_used);
        $this->assertFalse($requestLog->is_fallback);
    }

    public function test_dev_mode_callback_payload_structure(): void
    {
        Queue::fake([DeliverCallback::class]);
        config(['llm.dev_mode.latency_ms' => 0]);

        [$client, $requestLog] = $this->createDevModeSetup(true);

        $this->executeJob($requestLog->id);

        $pendingResponse = \App\Models\PendingResponse::where('request_log_id', $requestLog->id)->first();
        $payload = $pendingResponse->response_payload;

        $this->assertEquals('ok', $payload['status']);
        $this->assertArrayHasKey('meta', $payload);
        $this->assertArrayHasKey('provider', $payload);
        $this->assertArrayHasKey('result', $payload);
        $this->assertArrayHasKey('latency_ms', $payload);
        $this->assertEquals('stub', $payload['provider']['name']);
        $this->assertEquals('dev-mode-stub', $payload['provider']['model']);
        $this->assertEquals(config('llm.dev_mode.content'), $payload['result']['content']);
    }

    public function test_disabled_dev_mode_calls_real_provider(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                json_decode(file_get_contents(__DIR__ . '/../../Fixtures/responses/claude_success.json'), true),
                200,
            ),
        ]);

        Queue::fake([DeliverCallback::class]);

        [$client, $requestLog] = $this->createDevModeSetup(false);

        config([
            'llm.providers.claude' => [
                'endpoint' => 'https://api.anthropic.com/v1/messages',
                'api_key' => 'test-key',
                'default_model' => 'claude-sonnet-4-20250514',
                'default_max_tokens' => 4096,
            ],
        ]);

        $this->executeJob($requestLog->id);

        Http::assertSentCount(1);
    }
}
