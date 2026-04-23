<?php

namespace Tests\Feature\Commands;

use App\Components\CallbackDelivery\Enums\DeliveryStatus;
use App\Jobs\DeliverCallback;
use App\Models\ApiClient;
use App\Models\PendingResponse;
use App\Models\RequestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RetryCallbacksTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_jobs_for_pending_callbacks(): void
    {
        Queue::fake();

        $client = ApiClient::factory()->create();
        $requestLog = RequestLog::factory()->create(['api_client_id' => $client->id]);

        PendingResponse::create([
            'request_log_id' => $requestLog->id,
            'response_payload' => ['status' => 'ok'],
            'callback_url' => 'https://example.com/cb',
            'callback_method' => 'POST',
            'callback_headers' => [],
            'delivery_status' => DeliveryStatus::Pending,
            'delivery_attempts' => 1,
            'max_attempts' => 3,
            'retry_backoff' => 'exponential',
            'retry_initial_delay' => 1,
            'next_retry_at' => now()->subMinute(),
            'expires_at' => now()->addDay(),
        ]);

        $this->artisan('llm:retry-callbacks');

        Queue::assertPushed(DeliverCallback::class);
    }

    public function test_skips_callbacks_not_yet_due(): void
    {
        Queue::fake();

        $client = ApiClient::factory()->create();
        $requestLog = RequestLog::factory()->create(['api_client_id' => $client->id]);

        PendingResponse::create([
            'request_log_id' => $requestLog->id,
            'response_payload' => ['status' => 'ok'],
            'callback_url' => 'https://example.com/cb',
            'callback_method' => 'POST',
            'callback_headers' => [],
            'delivery_status' => DeliveryStatus::Pending,
            'delivery_attempts' => 1,
            'max_attempts' => 3,
            'retry_backoff' => 'exponential',
            'retry_initial_delay' => 1,
            'next_retry_at' => now()->addHour(),
            'expires_at' => now()->addDay(),
        ]);

        $this->artisan('llm:retry-callbacks');

        Queue::assertNotPushed(DeliverCallback::class);
    }
}
