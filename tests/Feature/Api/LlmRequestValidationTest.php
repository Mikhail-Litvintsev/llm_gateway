<?php

namespace Tests\Feature\Api;

use App\Components\Auth\KeyHasher;
use App\Models\ApiClient;
use App\Models\CallbackUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LlmRequestValidationTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey = 'lgw_test_validation_key_123';

    protected function setUp(): void
    {
        parent::setUp();

        $hasher = new KeyHasher();
        $client = ApiClient::factory()->create([
            'api_key_hash' => $hasher->hash($this->apiKey),
            'api_key_prefix' => $hasher->extractPrefix($this->apiKey),
            'is_active' => true,
            'signing_secret' => 'lgs_test_secret',
        ]);

        CallbackUrl::factory()->create([
            'api_client_id' => $client->id,
            'url' => 'https://example.com/callback',
        ]);

        Queue::fake();
    }

    private function sendXml(string $xml): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/api/v1/llm/request', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->apiKey,
            'CONTENT_TYPE' => 'application/xml',
        ], $xml);
    }

    public function test_rejects_missing_meta(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../Fixtures/xml/invalid_no_meta.xml');

        $response = $this->sendXml($xml);

        $response->assertStatus(400);
        $response->assertJson(['status' => 'error']);
    }

    public function test_rejects_missing_callback(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../Fixtures/xml/invalid_no_callback.xml');

        $response = $this->sendXml($xml);

        $response->assertStatus(400);
    }

    public function test_rejects_missing_request_id(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../Fixtures/xml/invalid_no_request_id.xml');

        $response = $this->sendXml($xml);

        $response->assertStatus(400);
    }

    public function test_rejects_no_user_block(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../Fixtures/xml/invalid_no_user_block.xml');

        $response = $this->sendXml($xml);

        $response->assertStatus(400);
    }

    public function test_rejects_invalid_type_role(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../Fixtures/xml/invalid_type_role.xml');

        $response = $this->sendXml($xml);

        $response->assertStatus(400);
    }

    public function test_rejects_duplicate_id(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../Fixtures/xml/invalid_duplicate_id.xml');

        $response = $this->sendXml($xml);

        $response->assertStatus(400);
    }

    public function test_rejects_dangling_description(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../Fixtures/xml/invalid_dangling_description.xml');

        $response = $this->sendXml($xml);

        $response->assertStatus(400);
    }

    public function test_rejects_history_order_violation(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../Fixtures/xml/invalid_history_order.xml');

        $response = $this->sendXml($xml);

        $response->assertStatus(400);
    }

    public function test_rejects_insecure_callback_url(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $xml = '<?xml version="1.0"?><llm_request version="3.0"><meta><request_id>req_ins</request_id></meta><prompt><block type="instruction" role="user">Hello</block></prompt><callback><url>http://example.com/callback</url></callback></llm_request>';

        $response = $this->sendXml($xml);

        $response->assertStatus(400);
        $this->assertStringContainsString('HTTPS', $response->json('error.message'));
    }
}
