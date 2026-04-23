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

class ProcessLlmRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_processes_request_and_delivers_callback(): void
    {
        Queue::fake([DeliverCallback::class]);

        Http::fake([
            'api.anthropic.com/*' => Http::response(
                json_decode(file_get_contents(__DIR__ . '/../../Fixtures/responses/claude_success.json'), true),
                200,
            ),
        ]);

        $client = ApiClient::factory()->create(['signing_secret' => 'lgs_test_secret', 'dev_mode' => false]);
        CallbackUrl::factory()->create(['api_client_id' => $client->id]);

        $requestLog = RequestLog::factory()->create([
            'api_client_id' => $client->id,
            'request_id' => 'req_process_001',
            'callback_url' => 'https://example.com/callback',
            'meta_data' => ['request_id' => 'req_process_001'],
            'status' => RequestStatus::Accepted,
        ]);

        PendingPrompt::create([
            'request_log_id' => $requestLog->id,
            'prompt_xml' => '<prompt><block type="instruction" role="user">Tell me a joke.</block></prompt>',
            'expires_at' => now()->addDays(3),
        ]);

        config([
            'llm.providers.claude' => [
                'endpoint' => 'https://api.anthropic.com/v1/messages',
                'api_key' => 'test-key',
                'default_model' => 'claude-sonnet-4-20250514',
                'default_max_tokens' => 4096,
            ],
        ]);

        $job = new ProcessLlmRequest($requestLog->id);
        $job->handle(
            app(\App\Components\RequestPipeline\XmlParser::class),
            app(\App\Components\PromptAssembler\PromptAssembler::class),
            app(\App\Components\ProviderGateway\ProviderGateway::class),
            app(\App\Components\ProviderGateway\ResponseParser::class),
            app(\App\Components\RateLimiter\RequestThrottle::class),
            app(\App\Components\RateLimiter\Claude\ClaudeTokenEstimator::class),
            app(\App\Components\RateLimiter\Claude\ClaudeTokenBudget::class),
        );

        $requestLog->refresh();
        $this->assertEquals(RequestStatus::Completed, $requestLog->status);
        $this->assertNotNull($requestLog->latency_ms);

        $this->assertDatabaseHas('raw_responses', ['request_log_id' => $requestLog->id]);
        $this->assertDatabaseHas('response_log', ['request_log_id' => $requestLog->id]);
        $this->assertDatabaseHas('pending_responses', ['request_log_id' => $requestLog->id]);

        // Pending prompt should be deleted after successful processing
        $this->assertDatabaseMissing('pending_prompts', ['request_log_id' => $requestLog->id]);

        Queue::assertPushed(DeliverCallback::class);
    }

    public function test_handles_missing_pending_prompt(): void
    {
        $client = ApiClient::factory()->create(['signing_secret' => 'lgs_test_secret', 'dev_mode' => false]);

        $requestLog = RequestLog::factory()->create([
            'api_client_id' => $client->id,
            'callback_url' => 'https://example.com/callback',
            'meta_data' => ['request_id' => 'req_missing'],
            'status' => RequestStatus::Accepted,
        ]);

        // No pending prompt created

        $job = new ProcessLlmRequest($requestLog->id);
        $job->handle(
            app(\App\Components\RequestPipeline\XmlParser::class),
            app(\App\Components\PromptAssembler\PromptAssembler::class),
            app(\App\Components\ProviderGateway\ProviderGateway::class),
            app(\App\Components\ProviderGateway\ResponseParser::class),
            app(\App\Components\RateLimiter\RequestThrottle::class),
            app(\App\Components\RateLimiter\Claude\ClaudeTokenEstimator::class),
            app(\App\Components\RateLimiter\Claude\ClaudeTokenBudget::class),
        );

        $requestLog->refresh();
        $this->assertEquals(RequestStatus::Failed, $requestLog->status);
        $this->assertEquals('INTERNAL_ERROR', $requestLog->error_code);
    }
}
