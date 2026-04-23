<?php

namespace Tests\Unit\Components\Auth;

use App\Components\Auth\CallbackUrlValidator;
use App\Models\CallbackUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallbackUrlValidatorTest extends TestCase
{
    use RefreshDatabase;
    private CallbackUrlValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new CallbackUrlValidator();
    }

    public function test_is_secure_accepts_https(): void
    {
        $this->assertTrue($this->validator->isSecure('https://example.com/callback'));
    }

    public function test_is_secure_rejects_http_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');
        $this->assertFalse($this->validator->isSecure('http://example.com/callback'));
    }

    public function test_is_secure_allows_http_in_local(): void
    {
        app()->detectEnvironment(fn () => 'local');
        $this->assertTrue($this->validator->isSecure('http://example.com/callback'));
    }

    public function test_validate_returns_true_for_registered_url(): void
    {
        $client = \App\Models\ApiClient::factory()->create();
        CallbackUrl::factory()->create([
            'api_client_id' => $client->id,
            'url' => 'https://example.com/callback',
            'is_active' => true,
        ]);

        $this->assertTrue($this->validator->validate($client->id, 'https://example.com/callback'));
    }

    public function test_validate_returns_false_for_unregistered_url(): void
    {
        $client = \App\Models\ApiClient::factory()->create();

        $this->assertFalse($this->validator->validate($client->id, 'https://unknown.com/callback'));
    }

    public function test_validate_returns_false_for_inactive_url(): void
    {
        $client = \App\Models\ApiClient::factory()->create();
        CallbackUrl::factory()->create([
            'api_client_id' => $client->id,
            'url' => 'https://example.com/callback',
            'is_active' => false,
        ]);

        $this->assertFalse($this->validator->validate($client->id, 'https://example.com/callback'));
    }
}
