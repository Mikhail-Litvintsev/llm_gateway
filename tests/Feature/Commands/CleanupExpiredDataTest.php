<?php

namespace Tests\Feature\Commands;

use App\Components\CallbackDelivery\Enums\DeliveryStatus;
use App\Models\ApiClient;
use App\Models\PendingPrompt;
use App\Models\PendingResponse;
use App\Models\RequestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanupExpiredDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_expired_pending_prompts(): void
    {
        $client = ApiClient::factory()->create();
        $requestLog = RequestLog::factory()->create(['api_client_id' => $client->id]);

        PendingPrompt::create([
            'request_log_id' => $requestLog->id,
            'prompt_xml' => '<prompt/>',
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('llm:cleanup-expired')->assertExitCode(0);

        $this->assertDatabaseCount('pending_prompts', 0);
    }

    public function test_keeps_non_expired_pending_prompts(): void
    {
        $client = ApiClient::factory()->create();
        $requestLog = RequestLog::factory()->create(['api_client_id' => $client->id]);

        PendingPrompt::create([
            'request_log_id' => $requestLog->id,
            'prompt_xml' => '<prompt/>',
            'expires_at' => now()->addDay(),
        ]);

        $this->artisan('llm:cleanup-expired')->assertExitCode(0);

        $this->assertDatabaseCount('pending_prompts', 1);
    }

    public function test_deletes_expired_pending_responses(): void
    {
        $client = ApiClient::factory()->create();
        $requestLog = RequestLog::factory()->create(['api_client_id' => $client->id]);

        PendingResponse::create([
            'request_log_id' => $requestLog->id,
            'response_payload' => ['status' => 'ok'],
            'callback_url' => 'https://example.com/cb',
            'callback_method' => 'POST',
            'callback_headers' => [],
            'delivery_status' => DeliveryStatus::Pending,
            'delivery_attempts' => 0,
            'max_attempts' => 3,
            'retry_backoff' => 'exponential',
            'retry_initial_delay' => 1,
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('llm:cleanup-expired')->assertExitCode(0);

        $this->assertDatabaseCount('pending_responses', 0);
    }
}
