<?php

namespace Tests\Feature\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateApiClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_api_client(): void
    {
        $this->artisan('llm:create-client', ['name' => 'Test Client'])
            ->assertExitCode(0)
            ->expectsOutputToContain('Client created');

        $this->assertDatabaseHas('api_clients', ['name' => 'Test Client', 'is_active' => true]);
    }

    public function test_creates_client_with_rate_limit(): void
    {
        $this->artisan('llm:create-client', [
            'name' => 'Rate Limited',
            '--rate-limit' => 100,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('api_clients', ['name' => 'Rate Limited', 'rate_limit' => 100]);
    }

    public function test_creates_client_with_providers(): void
    {
        $this->artisan('llm:create-client', [
            'name' => 'Provider Limited',
            '--providers' => 'claude,openai',
        ])->assertExitCode(0);

        $client = \App\Models\ApiClient::where('name', 'Provider Limited')->first();
        $this->assertEquals(['claude', 'openai'], $client->allowed_providers);
    }

    public function test_new_client_has_dev_mode_true_by_default(): void
    {
        $this->artisan('llm:create-client', ['name' => 'Dev Mode Client'])
            ->assertExitCode(0);

        $client = \App\Models\ApiClient::where('name', 'Dev Mode Client')->first();
        $this->assertTrue($client->dev_mode);
    }

    public function test_no_dev_mode_flag_creates_with_dev_mode_false(): void
    {
        $this->artisan('llm:create-client', [
            'name' => 'Production Client',
            '--no-dev-mode' => true,
        ])->assertExitCode(0);

        $client = \App\Models\ApiClient::where('name', 'Production Client')->first();
        $this->assertFalse($client->dev_mode);
    }
}
