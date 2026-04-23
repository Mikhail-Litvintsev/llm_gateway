<?php

declare(strict_types=1);

namespace Tests\Feature\Messages;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ShowMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Client $otherClient;

    private string $rawApiKey;

    private string $otherRawApiKey;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $generator = new KeyGenerator;
        $hasher = $this->app->make(KeyHasher::class);

        $workspace = ClaudeWorkspace::create([
            'name' => 'test-workspace',
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test-key'),
            'is_active' => true,
        ]);

        $this->rawApiKey = $generator->generateRawKey();
        $this->client = Client::create([
            'name' => 'test-client',
            'workspace_id' => $workspace->id,
            'api_key_hash' => $hasher->hash($this->rawApiKey),
            'api_key_prefix' => $generator->derivePrefix($this->rawApiKey),
            'signing_secret_current_encrypted' => Crypt::encryptString('secret-1'),
            'allowed_features' => [],
            'rate_limit_rpm' => 600,
            'monthly_spend_cap_usd' => 1000.00,
            'current_month_spend_usd' => 0,
            'is_dev_mode' => false,
        ]);

        $this->otherRawApiKey = $generator->generateRawKey();
        $this->otherClient = Client::create([
            'name' => 'other-client',
            'workspace_id' => $workspace->id,
            'api_key_hash' => $hasher->hash($this->otherRawApiKey),
            'api_key_prefix' => $generator->derivePrefix($this->otherRawApiKey),
            'signing_secret_current_encrypted' => Crypt::encryptString('secret-2'),
            'allowed_features' => [],
            'rate_limit_rpm' => 600,
            'monthly_spend_cap_usd' => 1000.00,
            'current_month_spend_usd' => 0,
            'is_dev_mode' => false,
        ]);
    }

    #[Test]
    public function show_returns_request_details(): void
    {
        $requestId = 'req_'.str_repeat('A', 24);

        $this->seedRequest($requestId, $this->client->id, 'completed');

        $response = $this->getJson("/api/v1/messages/{$requestId}", [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('X-Gateway-Request-Id', $requestId);

        $body = $response->json();
        $this->assertSame($requestId, $body['request_id']);
        $this->assertSame('completed', $body['status']);
        $this->assertSame('claude-sonnet', $body['model_alias']);
        $this->assertSame('messages', $body['endpoint']);
    }

    #[Test]
    public function show_returns_billing_when_usage_exists(): void
    {
        $requestId = 'req_'.str_repeat('B', 24);

        $this->seedRequest($requestId, $this->client->id, 'completed');
        $this->seedUsage($requestId, 0.005);

        $response = $this->getJson("/api/v1/messages/{$requestId}", [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertNotNull($body['billing']);
        $this->assertSame(0.005, $body['billing']['cost_usd']);
    }

    #[Test]
    public function show_returns_anthropic_response_when_raw_exists(): void
    {
        $requestId = 'req_'.str_repeat('C', 24);

        $this->seedRequest($requestId, $this->client->id, 'completed');
        $this->seedRaw($requestId, '{"sent": true}', '{"id": "msg_123", "type": "message"}');

        $response = $this->getJson("/api/v1/messages/{$requestId}", [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertSame('msg_123', $body['anthropic_response']['id']);
    }

    #[Test]
    public function show_returns_404_for_nonexistent_request(): void
    {
        $requestId = 'req_'.str_repeat('Z', 24);

        $response = $this->getJson("/api/v1/messages/{$requestId}", [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(404);

        $body = $response->json();
        $this->assertSame('not_found_error', $body['error']['type']);
    }

    #[Test]
    public function show_returns_404_when_request_belongs_to_another_client(): void
    {
        $requestId = 'req_'.str_repeat('D', 24);

        $this->seedRequest($requestId, $this->otherClient->id, 'completed');

        $response = $this->getJson("/api/v1/messages/{$requestId}", [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(404);

        $body = $response->json();
        $this->assertSame('not_found_error', $body['error']['type']);
    }

    #[Test]
    public function show_without_auth_returns_401(): void
    {
        $requestId = 'req_'.str_repeat('E', 24);

        $response = $this->getJson("/api/v1/messages/{$requestId}");

        $response->assertStatus(401);
    }

    #[Test]
    public function show_returns_error_details_when_request_failed(): void
    {
        $requestId = 'req_'.str_repeat('F', 24);

        $this->seedRequest($requestId, $this->client->id, 'failed_server_error', 'overloaded_error', 'Overloaded');

        $response = $this->getJson("/api/v1/messages/{$requestId}", [
            'Authorization' => 'Bearer '.$this->rawApiKey,
        ]);

        $response->assertStatus(200);

        $body = $response->json();
        $this->assertNotNull($body['error']);
        $this->assertSame('overloaded_error', $body['error']['type']);
        $this->assertSame('Overloaded', $body['error']['message']);
    }

    private function seedRequest(
        string $requestId,
        int $clientId,
        string $status,
        ?string $errorType = null,
        ?string $errorMessage = null,
    ): void {
        DB::table('requests')->insert([
            'request_id' => $requestId,
            'client_id' => $clientId,
            'endpoint' => 'messages',
            'mode' => 'sync',
            'model_alias' => 'claude-sonnet',
            'model_snapshot' => 'claude-sonnet-4-6',
            'status' => $status,
            'http_status' => $status === 'completed' ? 200 : 529,
            'error_type' => $errorType,
            'error_message' => $errorMessage,
            'created_at' => now(),
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }

    private function seedUsage(string $requestId, float $costUsd): void
    {
        DB::table('request_usage')->insert([
            'request_id' => $requestId,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cost_usd' => $costUsd,
            'cost_breakdown' => json_encode(['input' => $costUsd]),
        ]);
    }

    private function seedRaw(string $requestId, string $requestPayload, string $responsePayload): void
    {
        DB::table('request_raw')->insert([
            'request_id' => $requestId,
            'request_payload' => $requestPayload,
            'response_payload' => $responsePayload,
            'retention_until' => now()->addDays(14),
        ]);
    }
}
