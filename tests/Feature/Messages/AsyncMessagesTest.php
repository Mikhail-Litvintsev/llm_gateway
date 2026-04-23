<?php

declare(strict_types=1);

namespace Tests\Feature\Messages;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Jobs\DeliverWebhook;
use App\Jobs\ProcessAsyncMessage;
use App\Jobs\Scheduled\RetryFailedWebhooks;
use App\Models\Client;
use App\Models\ClaudeWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AsyncMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private string $rawApiKey;

    protected function setUp(): void
    {
        parent::setUp();

        config(['llm.auth.api_key_pepper' => 'test-pepper']);

        $generator = new KeyGenerator();
        $this->rawApiKey = $generator->generateRawKey();
        $hasher = new KeyHasher('test-pepper');

        $workspace = ClaudeWorkspace::create([
            'name' => 'test-workspace',
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        $this->client = Client::create([
            'name' => 'test-client',
            'workspace_id' => $workspace->id,
            'api_key_hash' => $hasher->hash($this->rawApiKey),
            'api_key_prefix' => $generator->derivePrefix($this->rawApiKey),
            'signing_secret_current_encrypted' => Crypt::encryptString('whsec_test'),
            'allowed_features' => ['webhook' => true],
            'rate_limit_rpm' => 60,
            'monthly_spend_cap_usd' => null,
            'current_month_spend_usd' => 0,
            'is_dev_mode' => false,
        ]);
    }

    private function asyncPayload(array $overrides = []): array
    {
        return array_merge([
            'model' => 'claude-sonnet',
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
            ],
            'max_tokens' => 1024,
            'callback_url' => 'https://example.com/webhook',
        ], $overrides);
    }

    private function authenticatedPost(string $uri, array $data): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->rawApiKey,
            'Content-Type' => 'application/json',
        ])->postJson($uri, $data);
    }

    private function actAsClient(): void
    {
        $client = $this->client;
        $this->app['router']->matched(function ($event) use ($client): void {
            $event->request->attributes->set('auth.client', $client);
        });
    }

    private function seedCallbackWhitelist(string $url): void
    {
        DB::table('client_callback_urls')->insert([
            'client_id' => $this->client->id,
            'url' => $url,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function async_submit_returns_202_and_dispatches_job(): void
    {
        Queue::fake();
        $this->actAsClient();
        $this->seedCallbackWhitelist('https://example.com/webhook');

        $response = $this->authenticatedPost('/api/v1/messages/async', $this->asyncPayload());

        $response->assertStatus(202);
        $response->assertJsonStructure([
            'request_id',
            'status',
            'estimated_cost_usd',
            'callback_url',
            'expires_at',
        ]);
        $response->assertJson(['status' => 'accepted']);

        $requestId = $response->json('request_id');
        $this->assertStringStartsWith('req_', $requestId);

        $this->assertDatabaseHas('requests', [
            'request_id' => $requestId,
            'client_id' => $this->client->id,
            'status' => 'accepted',
        ]);

        $this->assertDatabaseHas('async_pending', [
            'request_id' => $requestId,
            'status' => 'queued',
            'callback_attempts' => 0,
        ]);

        Queue::assertPushed(ProcessAsyncMessage::class, function (ProcessAsyncMessage $job) use ($requestId): bool {
            return $job->requestId === $requestId;
        });
    }

    #[Test]
    public function callback_url_not_whitelisted_returns_400(): void
    {
        $this->actAsClient();

        $response = $this->authenticatedPost('/api/v1/messages/async', $this->asyncPayload([
            'callback_url' => 'https://evil.com/steal-data',
        ]));

        $response->assertStatus(400);
        $body = $response->json();
        $this->assertSame('error', $body['type'] ?? $body['status'] ?? null);
    }

    #[Test]
    public function missing_callback_url_returns_400(): void
    {
        $this->actAsClient();

        $payload = $this->asyncPayload();
        unset($payload['callback_url']);

        $response = $this->authenticatedPost('/api/v1/messages/async', $payload);

        $response->assertStatus(400);
    }

    #[Test]
    public function retry_failed_webhooks_picks_eligible_rows(): void
    {
        Queue::fake();

        DB::table('requests')->insert([
            'request_id' => 'req_retrytest000000000000001',
            'client_id' => $this->client->id,
            'endpoint' => 'messages',
            'mode' => 'async_callback',
            'model_alias' => 'claude-sonnet',
            'model_snapshot' => 'claude-sonnet-4-6',
            'status' => 'completed',
            'created_at' => now(),
        ]);

        DB::table('async_pending')->insert([
            'request_id' => 'req_retrytest000000000000001',
            'payload_for_anthropic' => json_encode(['model' => 'claude-sonnet']),
            'callback_url' => 'https://example.com/webhook',
            'status' => 'processing',
            'callback_attempts' => 2,
            'next_attempt_at' => now()->subMinute(),
            'expires_at' => now()->addDays(3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('requests')->insert([
            'request_id' => 'req_retrytest000000000000002',
            'client_id' => $this->client->id,
            'endpoint' => 'messages',
            'mode' => 'async_callback',
            'model_alias' => 'claude-sonnet',
            'model_snapshot' => 'claude-sonnet-4-6',
            'status' => 'completed',
            'created_at' => now(),
        ]);

        DB::table('async_pending')->insert([
            'request_id' => 'req_retrytest000000000000002',
            'payload_for_anthropic' => json_encode(['model' => 'claude-sonnet']),
            'callback_url' => 'https://example.com/webhook',
            'status' => 'delivered',
            'callback_attempts' => 1,
            'next_attempt_at' => null,
            'expires_at' => now()->addDays(3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $handler = new RetryFailedWebhooks();
        $handler();

        Queue::assertPushed(DeliverWebhook::class, 1);
        Queue::assertPushed(DeliverWebhook::class, function (DeliverWebhook $job): bool {
            return $job->requestId === 'req_retrytest000000000000001';
        });
    }

    #[Test]
    public function retry_skips_rows_with_future_next_attempt(): void
    {
        Queue::fake();

        DB::table('requests')->insert([
            'request_id' => 'req_futuretest00000000000001',
            'client_id' => $this->client->id,
            'endpoint' => 'messages',
            'mode' => 'async_callback',
            'model_alias' => 'claude-sonnet',
            'model_snapshot' => 'claude-sonnet-4-6',
            'status' => 'completed',
            'created_at' => now(),
        ]);

        DB::table('async_pending')->insert([
            'request_id' => 'req_futuretest00000000000001',
            'payload_for_anthropic' => json_encode(['model' => 'claude-sonnet']),
            'callback_url' => 'https://example.com/webhook',
            'status' => 'processing',
            'callback_attempts' => 1,
            'next_attempt_at' => now()->addHour(),
            'expires_at' => now()->addDays(3),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $handler = new RetryFailedWebhooks();
        $handler();

        Queue::assertNotPushed(DeliverWebhook::class);
    }
}
