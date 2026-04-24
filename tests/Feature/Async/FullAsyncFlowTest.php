<?php

declare(strict_types=1);

namespace Tests\Feature\Async;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Components\Logging\Enums\RequestStatus;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FullAsyncFlowTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private string $plainApiKey;

    private string $callbackUrl = 'http://client.test/hooks/async';

    protected function setUp(): void
    {
        parent::setUp();

        config(['llm.auth.api_key_pepper' => 'test-pepper']);

        [$this->client, $this->plainApiKey] = $this->createClientWithKey();
        $this->whitelistCallbackUrl($this->client->id, $this->callbackUrl);
    }

    #[Test]
    public function accept_async_request_process_it_and_deliver_webhook(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response(
                json_encode([
                    'id' => 'msg_test_id',
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'hello from claude']],
                    'model' => 'claude-sonnet-4-6',
                    'stop_reason' => 'end_turn',
                    'usage' => [
                        'input_tokens' => 10,
                        'output_tokens' => 7,
                        'cache_read_input_tokens' => 0,
                        'cache_creation_input_tokens' => 0,
                    ],
                ]),
                200,
                [
                    'content-type' => 'application/json',
                    'request-id' => 'req_01AN',
                ],
            ),
            $this->callbackUrl => Http::response('', 200),
        ]);

        $response = $this->postJson(
            '/api/v1/messages/async',
            [
                'model' => 'claude-sonnet',
                'messages' => [['role' => 'user', 'content' => 'hi']],
                'max_tokens' => 128,
                'callback_url' => $this->callbackUrl,
            ],
            $this->authHeaders(),
        );

        $response->assertStatus(202);
        $requestId = $response->json('request_id');
        $this->assertNotNull($requestId);

        $this->assertSame(
            RequestStatus::Completed->value,
            DB::table('requests')->where('request_id', $requestId)->value('status'),
        );

        $this->assertTrue(
            DB::table('request_raw')
                ->where('request_id', $requestId)
                ->whereNotNull('response_payload')
                ->exists(),
        );

        $this->assertTrue(
            DB::table('request_usage')->where('request_id', $requestId)->exists(),
        );

        $asyncPending = DB::table('async_pending')->where('request_id', $requestId)->first();
        $this->assertSame('delivered', $asyncPending->status);
        $this->assertSame(1, (int) $asyncPending->callback_attempts);

        Http::assertSent(function ($request): bool {
            return $request->url() === $this->callbackUrl
                && $request->method() === 'POST'
                && $request->hasHeader('X-Webhook-Signature');
        });
    }

    #[Test]
    public function webhook_delivery_failure_schedules_retry(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response(
                json_encode([
                    'id' => 'msg_test_id',
                    'type' => 'message',
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'ok']],
                    'model' => 'claude-sonnet-4-6',
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                    'stop_reason' => 'end_turn',
                ]),
                200,
            ),
            $this->callbackUrl => Http::response('bad gateway', 502),
        ]);

        $response = $this->postJson(
            '/api/v1/messages/async',
            [
                'model' => 'claude-sonnet',
                'messages' => [['role' => 'user', 'content' => 'hi']],
                'max_tokens' => 128,
                'callback_url' => $this->callbackUrl,
            ],
            $this->authHeaders(),
        );

        $response->assertStatus(202);
        $requestId = $response->json('request_id');

        $asyncPending = DB::table('async_pending')->where('request_id', $requestId)->first();
        $this->assertSame('processing', $asyncPending->status);
        $this->assertSame(1, (int) $asyncPending->callback_attempts);
        $this->assertNotNull($asyncPending->next_attempt_at);
    }

    /**
     * @return array{0: Client, 1: string}
     */
    private function createClientWithKey(): array
    {
        $generator = new KeyGenerator;
        $hasher = new KeyHasher('test-pepper');

        $workspace = ClaudeWorkspace::create([
            'name' => 'async-e2e-ws-'.bin2hex(random_bytes(3)),
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        $rawKey = $generator->generateRawKey();
        $client = Client::create([
            'name' => 'async-e2e-client',
            'workspace_id' => $workspace->id,
            'api_key_hash' => $hasher->hash($rawKey),
            'api_key_prefix' => $generator->derivePrefix($rawKey),
            'signing_secret_current_encrypted' => Crypt::encryptString('whsec_integration_test'),
            'allowed_features' => ['webhook' => true],
            'rate_limit_rpm' => 100,
            'is_dev_mode' => false,
            'default_model_alias' => 'claude-sonnet',
        ]);

        return [$client, $rawKey];
    }

    private function whitelistCallbackUrl(int $clientId, string $url): void
    {
        DB::table('client_callback_urls')->insert([
            'client_id' => $clientId,
            'url' => $url,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->plainApiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }
}
