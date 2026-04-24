<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Components\Delivery\Webhook\Webhook;
use App\Components\Logging\Enums\RequestStatus;
use App\Jobs\DeliverWebhook;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use App\Repositories\AsyncPendingRepository;
use App\Repositories\RequestRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use TypeError;

final class DeliverWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private string $requestId;

    private string $callbackUrl = 'http://client.test/hooks/async';

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->seedClient();
        $this->requestId = 'req_'.substr(bin2hex(random_bytes(12)), 0, 24);
        $this->seedCompletedRequest($this->requestId);
    }

    #[Test]
    public function successful_delivery_updates_status_to_delivered(): void
    {
        Http::fake([
            $this->callbackUrl => Http::response('', 200),
        ]);

        $job = new DeliverWebhook($this->requestId);
        $job->handle(app(Webhook::class), app(RequestRepository::class), app(AsyncPendingRepository::class));

        $row = DB::table('async_pending')->where('request_id', $this->requestId)->first();
        $this->assertSame('delivered', $row->status);
        $this->assertSame(1, (int) $row->callback_attempts);
        $this->assertNull($row->next_attempt_at);
    }

    #[Test]
    public function connection_failure_schedules_next_attempt_with_backoff(): void
    {
        Http::fake([
            $this->callbackUrl => function (): void {
                throw new ConnectionException('client unreachable');
            },
        ]);

        $job = new DeliverWebhook($this->requestId);
        $job->handle(app(Webhook::class), app(RequestRepository::class), app(AsyncPendingRepository::class));

        $row = DB::table('async_pending')->where('request_id', $this->requestId)->first();
        $this->assertSame('processing', $row->status);
        $this->assertSame(1, (int) $row->callback_attempts);
        $this->assertNotNull($row->next_attempt_at);

        $expected = now()->addSeconds(10);
        $actual = Carbon::parse($row->next_attempt_at);
        $this->assertLessThanOrEqual(5, abs($expected->diffInSeconds($actual)));
    }

    #[Test]
    public function http_error_response_schedules_retry_without_exception(): void
    {
        Http::fake([
            $this->callbackUrl => Http::response('fail', 500),
        ]);

        $job = new DeliverWebhook($this->requestId);
        $job->handle(app(Webhook::class), app(RequestRepository::class), app(AsyncPendingRepository::class));

        $row = DB::table('async_pending')->where('request_id', $this->requestId)->first();
        $this->assertSame('processing', $row->status);
        $this->assertSame(1, (int) $row->callback_attempts);
    }

    #[Test]
    public function max_attempts_reached_transitions_to_exhausted(): void
    {
        DB::table('async_pending')
            ->where('request_id', $this->requestId)
            ->update(['callback_attempts' => 9, 'status' => 'processing']);

        Http::fake([
            $this->callbackUrl => Http::response('', 500),
        ]);

        $job = new DeliverWebhook($this->requestId);
        $job->handle(app(Webhook::class), app(RequestRepository::class), app(AsyncPendingRepository::class));

        $row = DB::table('async_pending')->where('request_id', $this->requestId)->first();
        $this->assertSame('exhausted', $row->status);
        $this->assertSame(10, (int) $row->callback_attempts);
        $this->assertNull($row->next_attempt_at);

        $requestStatus = DB::table('requests')->where('request_id', $this->requestId)->value('status');
        $this->assertSame(RequestStatus::FailedCallbackDelivery->value, $requestStatus);
    }

    #[Test]
    public function failed_hook_advances_state_machine_and_logs(): void
    {
        $job = new DeliverWebhook($this->requestId);
        $job->failed(new TypeError('boom inside handle'));

        $row = DB::table('async_pending')->where('request_id', $this->requestId)->first();
        $this->assertSame('processing', $row->status);
        $this->assertSame(1, (int) $row->callback_attempts);
        $this->assertNotNull($row->next_attempt_at);
    }

    #[Test]
    public function failed_hook_on_last_attempt_transitions_to_exhausted(): void
    {
        DB::table('async_pending')
            ->where('request_id', $this->requestId)
            ->update(['callback_attempts' => 9, 'status' => 'processing']);

        $job = new DeliverWebhook($this->requestId);
        $job->failed(new TypeError('terminal crash'));

        $row = DB::table('async_pending')->where('request_id', $this->requestId)->first();
        $this->assertSame('exhausted', $row->status);
        $this->assertSame(10, (int) $row->callback_attempts);

        $requestStatus = DB::table('requests')->where('request_id', $this->requestId)->value('status');
        $this->assertSame(RequestStatus::FailedCallbackDelivery->value, $requestStatus);
    }

    #[Test]
    public function failed_hook_skips_on_already_delivered(): void
    {
        DB::table('async_pending')
            ->where('request_id', $this->requestId)
            ->update(['status' => 'delivered', 'callback_attempts' => 1]);

        $job = new DeliverWebhook($this->requestId);
        $job->failed(new RuntimeException('late crash'));

        $row = DB::table('async_pending')->where('request_id', $this->requestId)->first();
        $this->assertSame('delivered', $row->status);
        $this->assertSame(1, (int) $row->callback_attempts);
    }

    #[Test]
    public function handle_skips_when_already_delivered(): void
    {
        DB::table('async_pending')
            ->where('request_id', $this->requestId)
            ->update(['status' => 'delivered']);

        Http::fake([$this->callbackUrl => Http::response('', 200)]);

        $job = new DeliverWebhook($this->requestId);
        $job->handle(app(Webhook::class), app(RequestRepository::class), app(AsyncPendingRepository::class));

        Http::assertNothingSent();
    }

    private function seedClient(): Client
    {
        $workspace = ClaudeWorkspace::create([
            'name' => 'dw-test-ws-'.bin2hex(random_bytes(3)),
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        return Client::create([
            'name' => 'dw-test-client',
            'workspace_id' => $workspace->id,
            'api_key_hash' => random_bytes(32),
            'api_key_prefix' => 'gw_live_xxx',
            'signing_secret_current_encrypted' => Crypt::encryptString('whsec_secret'),
            'allowed_features' => [],
            'rate_limit_rpm' => 60,
            'is_dev_mode' => false,
        ]);
    }

    private function seedCompletedRequest(string $requestId): void
    {
        DB::table('requests')->insert([
            'request_id' => $requestId,
            'client_id' => $this->client->id,
            'endpoint' => 'messages',
            'mode' => 'async_callback',
            'model_alias' => 'claude-sonnet',
            'model_snapshot' => 'claude-sonnet-4-6',
            'status' => RequestStatus::Completed->value,
            'http_status' => 200,
            'anthropic_request_id' => 'req_01AN',
            'created_at' => now(),
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        DB::table('async_pending')->insert([
            'request_id' => $requestId,
            'payload_for_anthropic' => '{"messages":[]}',
            'callback_url' => $this->callbackUrl,
            'status' => 'processing',
            'callback_attempts' => 0,
            'next_attempt_at' => null,
            'expires_at' => now()->addDays(3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('request_usage')->insert([
            'request_id' => $requestId,
            'input_tokens' => 10,
            'output_tokens' => 5,
            'cost_usd' => '0.00001000',
            'cost_breakdown' => '{}',
        ]);

        DB::table('request_raw')->insert([
            'request_id' => $requestId,
            'request_payload' => '{}',
            'response_payload' => '{"id":"msg","content":[]}',
            'retention_until' => now()->addDays(14),
        ]);
    }
}
