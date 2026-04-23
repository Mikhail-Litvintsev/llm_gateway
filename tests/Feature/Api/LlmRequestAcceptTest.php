<?php

namespace Tests\Feature\Api;

use App\Components\Auth\KeyHasher;
use App\Jobs\ProcessLlmRequest;
use App\Models\ApiClient;
use App\Models\CallbackUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LlmRequestAcceptTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey = 'lgw_test_accept_key_1234567';
    private ApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $hasher = new KeyHasher();
        $this->client = ApiClient::factory()->create([
            'api_key_hash' => $hasher->hash($this->apiKey),
            'api_key_prefix' => $hasher->extractPrefix($this->apiKey),
            'is_active' => true,
            'signing_secret' => 'lgs_test_secret',
        ]);

        CallbackUrl::factory()->create([
            'api_client_id' => $this->client->id,
            'url' => 'https://example.com/callback',
        ]);
    }

    private function sendXml(string $xml, string $contentType = 'application/xml'): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            'POST',
            '/api/v1/llm/request',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->apiKey,
                'CONTENT_TYPE' => $contentType,
            ],
            $xml,
        );
    }

    public function test_accepts_valid_request_and_returns_202(): void
    {
        Queue::fake();

        $xml = file_get_contents(__DIR__ . '/../../Fixtures/xml/valid_minimal.xml');

        $response = $this->sendXml($xml, 'application/xml; charset=utf-8');

        $response->assertStatus(202);

        $json = $response->json();
        $this->assertEquals('accepted', $json['status']);
        $this->assertEquals('req_001', $json['request_id']);

        Queue::assertPushed(ProcessLlmRequest::class);
    }

    public function test_rejects_non_xml_content_type(): void
    {
        $response = $this->call(
            'POST',
            '/api/v1/llm/request',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->apiKey,
                'CONTENT_TYPE' => 'application/json',
            ],
            '{}',
        );

        $response->assertStatus(415);
        $response->assertJson(['status' => 'error', 'error' => ['code' => 'INVALID_CONTENT_TYPE']]);
    }

    public function test_rejects_malformed_xml(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../Fixtures/xml/invalid_malformed.xml');

        $response = $this->sendXml($xml);

        $response->assertStatus(400);
        $response->assertJson(['status' => 'error']);
    }

    public function test_creates_request_log_on_accept(): void
    {
        Queue::fake();

        $xml = file_get_contents(__DIR__ . '/../../Fixtures/xml/valid_minimal.xml');

        $this->sendXml($xml);

        $this->assertDatabaseHas('request_log', [
            'request_id' => 'req_001',
            'api_client_id' => $this->client->id,
            'status' => 'accepted',
        ]);
    }

    public function test_creates_pending_prompt_on_accept(): void
    {
        Queue::fake();

        $xml = file_get_contents(__DIR__ . '/../../Fixtures/xml/valid_minimal.xml');

        $this->sendXml($xml);

        $requestLog = \App\Models\RequestLog::where('request_id', 'req_001')->first();
        $this->assertNotNull($requestLog);

        $this->assertDatabaseHas('pending_prompts', [
            'request_log_id' => $requestLog->id,
        ]);
    }
}
