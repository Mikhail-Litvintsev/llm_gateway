<?php

declare(strict_types=1);

namespace Tests\Feature\Async;

use App\Components\Logging\Enums\RequestStatus;
use App\Jobs\DeliverWebhook;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WebhookPermanentFailTest extends TestCase
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
    public function permanent_fail_400_exhausts_immediately_without_retry(): void
    {
        Http::fake([
            $this->callbackUrl => Http::response('bad payload', 400),
        ]);

        DeliverWebhook::dispatch($this->requestId);

        $pending = DB::table('async_pending')->where('request_id', $this->requestId)->first();
        $this->assertSame('exhausted', $pending->status);
        $this->assertSame(1, (int) $pending->callback_attempts);
        $this->assertNull($pending->next_attempt_at);

        $requestStatus = DB::table('requests')->where('request_id', $this->requestId)->value('status');
        $this->assertSame(RequestStatus::FailedCallbackDelivery->value, $requestStatus);

        Http::assertSentCount(1);
    }

    #[Test]
    public function permanent_fail_404_exhausts_immediately_without_retry(): void
    {
        Http::fake([
            $this->callbackUrl => Http::response('not found', 404),
        ]);

        DeliverWebhook::dispatch($this->requestId);

        $pending = DB::table('async_pending')->where('request_id', $this->requestId)->first();
        $this->assertSame('exhausted', $pending->status);
        $this->assertSame(1, (int) $pending->callback_attempts);
        Http::assertSentCount(1);
    }

    #[Test]
    public function transient_5xx_schedules_retry_with_backoff(): void
    {
        Http::fake([
            $this->callbackUrl => Http::response('boom', 503),
        ]);

        DeliverWebhook::dispatch($this->requestId);

        $pending = DB::table('async_pending')->where('request_id', $this->requestId)->first();
        $this->assertSame('processing', $pending->status);
        $this->assertSame(1, (int) $pending->callback_attempts);
        $this->assertNotNull($pending->next_attempt_at);
        Http::assertSentCount(1);
    }

    private function seedClient(): Client
    {
        $workspace = ClaudeWorkspace::create([
            'name' => 'wpf-ws-'.bin2hex(random_bytes(3)),
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        return Client::create([
            'name' => 'wpf-client',
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
