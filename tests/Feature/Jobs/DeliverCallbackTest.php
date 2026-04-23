<?php

namespace Tests\Feature\Jobs;

use App\Components\CallbackDelivery\Enums\DeliveryStatus;
use App\Jobs\DeliverCallback;
use App\Models\ApiClient;
use App\Models\PendingResponse;
use App\Models\RequestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeliverCallbackTest extends TestCase
{
    use RefreshDatabase;

    private function createPendingResponse(): PendingResponse
    {
        $client = ApiClient::factory()->create(['signing_secret' => 'lgs_test_secret']);
        $requestLog = RequestLog::factory()->create([
            'api_client_id' => $client->id,
            'meta_data' => ['request_id' => 'req_cb_001'],
        ]);

        return PendingResponse::create([
            'request_log_id' => $requestLog->id,
            'response_payload' => ['status' => 'ok', 'meta' => ['request_id' => 'req_cb_001']],
            'callback_url' => 'https://example.com/callback',
            'callback_method' => 'POST',
            'callback_headers' => [],
            'delivery_status' => DeliveryStatus::Pending,
            'delivery_attempts' => 0,
            'max_attempts' => 3,
            'retry_backoff' => 'exponential',
            'retry_initial_delay' => 1,
            'expires_at' => now()->addDay(),
        ]);
    }

    public function test_delivers_callback_successfully(): void
    {
        Http::fake([
            'example.com/callback' => Http::response('', 200),
        ]);

        $pending = $this->createPendingResponse();

        $job = new DeliverCallback($pending->request_log_id);
        $job->handle(app(\App\Components\CallbackDelivery\CallbackDelivery::class));

        $pending->refresh();
        $this->assertEquals(DeliveryStatus::Delivered, $pending->delivery_status);
        $this->assertEquals(1, $pending->delivery_attempts);
    }

    public function test_marks_failed_on_client_error(): void
    {
        Http::fake([
            'example.com/callback' => Http::response('Not Found', 404),
        ]);

        $pending = $this->createPendingResponse();

        $job = new DeliverCallback($pending->request_log_id);
        $job->handle(app(\App\Components\CallbackDelivery\CallbackDelivery::class));

        $pending->refresh();
        $this->assertEquals(DeliveryStatus::Failed, $pending->delivery_status);
    }

    public function test_schedules_retry_on_server_error(): void
    {
        Http::fake([
            'example.com/callback' => Http::response('Server Error', 500),
        ]);
        Queue::fake();

        $pending = $this->createPendingResponse();

        $job = new DeliverCallback($pending->request_log_id);
        $job->handle(app(\App\Components\CallbackDelivery\CallbackDelivery::class));

        $pending->refresh();
        $this->assertEquals(DeliveryStatus::Pending, $pending->delivery_status);
        $this->assertEquals(1, $pending->delivery_attempts);
        $this->assertNotNull($pending->next_retry_at);
    }
}
