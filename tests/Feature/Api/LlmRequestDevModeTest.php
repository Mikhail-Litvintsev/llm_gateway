<?php

namespace Tests\Feature\Api;

use App\Components\Auth\KeyHasher;
use App\Jobs\ProcessLlmRequest;
use App\Models\ApiClient;
use App\Models\CallbackUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LlmRequestDevModeTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey = 'lgw_test_devmode_key_12345';

    private function createClient(bool $devMode): ApiClient
    {
        $hasher = new KeyHasher();
        $client = ApiClient::factory()->create([
            'api_key_hash' => $hasher->hash($this->apiKey),
            'api_key_prefix' => $hasher->extractPrefix($this->apiKey),
            'is_active' => true,
            'signing_secret' => 'lgs_test_secret',
            'dev_mode' => $devMode,
        ]);

        CallbackUrl::factory()->create([
            'api_client_id' => $client->id,
            'url' => 'https://example.com/callback',
        ]);

        return $client;
    }

    private function sendXml(string $xml): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            'POST',
            '/api/v1/llm/request',
            [],
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->apiKey,
                'CONTENT_TYPE' => 'application/xml',
            ],
            $xml,
        );
    }

    public function test_accept_response_includes_dev_mode_flag(): void
    {
        Queue::fake();

        $this->createClient(true);

        $xml = file_get_contents(__DIR__ . '/../../Fixtures/xml/valid_minimal.xml');
        $response = $this->sendXml($xml);

        $response->assertStatus(202);
        $json = $response->json();
        $this->assertTrue($json['dev_mode']);
    }

    public function test_accept_response_dev_mode_false(): void
    {
        Queue::fake();

        $this->createClient(false);

        $xml = file_get_contents(__DIR__ . '/../../Fixtures/xml/valid_minimal.xml');
        $response = $this->sendXml($xml);

        $response->assertStatus(202);
        $json = $response->json();
        $this->assertFalse($json['dev_mode']);
    }

    public function test_full_dev_mode_cycle(): void
    {
        Queue::fake([ProcessLlmRequest::class]);

        $client = $this->createClient(true);
        config(['llm.dev_mode.latency_ms' => 0]);

        $xml = file_get_contents(__DIR__ . '/../../Fixtures/xml/valid_minimal.xml');
        $response = $this->sendXml($xml);

        $response->assertStatus(202);
        $this->assertTrue($response->json('dev_mode'));

        // Verify job was dispatched
        Queue::assertPushed(ProcessLlmRequest::class);
    }
}
