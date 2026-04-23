<?php

namespace Tests\Feature\Commands;

use App\Models\ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToggleDevModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_enable_sets_dev_mode_true(): void
    {
        $client = ApiClient::factory()->create(['dev_mode' => false]);

        $this->artisan('llm:toggle-dev-mode', ['client' => $client->id, '--enable' => true])
            ->assertExitCode(0);

        $this->assertTrue($client->fresh()->dev_mode);
    }

    public function test_disable_sets_dev_mode_false(): void
    {
        $client = ApiClient::factory()->create(['dev_mode' => true]);

        $this->artisan('llm:toggle-dev-mode', ['client' => $client->id, '--disable' => true])
            ->assertExitCode(0);

        $this->assertFalse($client->fresh()->dev_mode);
    }

    public function test_toggle_inverts_value(): void
    {
        $client = ApiClient::factory()->create(['dev_mode' => true]);

        $this->artisan('llm:toggle-dev-mode', ['client' => $client->id])
            ->assertExitCode(0);

        $this->assertFalse($client->fresh()->dev_mode);

        $this->artisan('llm:toggle-dev-mode', ['client' => $client->id])
            ->assertExitCode(0);

        $this->assertTrue($client->fresh()->dev_mode);
    }

    public function test_both_flags_returns_error(): void
    {
        $client = ApiClient::factory()->create();

        $this->artisan('llm:toggle-dev-mode', [
            'client' => $client->id,
            '--enable' => true,
            '--disable' => true,
        ])->assertExitCode(1);
    }

    public function test_unknown_client_returns_error(): void
    {
        $this->artisan('llm:toggle-dev-mode', ['client' => '99999'])
            ->assertExitCode(1)
            ->expectsOutputToContain('not found');
    }

    public function test_finds_client_by_name(): void
    {
        $client = ApiClient::factory()->create(['name' => 'TestApp', 'dev_mode' => false]);

        $this->artisan('llm:toggle-dev-mode', ['client' => 'TestApp', '--enable' => true])
            ->assertExitCode(0);

        $this->assertTrue($client->fresh()->dev_mode);
    }

    public function test_finds_client_by_id(): void
    {
        $client = ApiClient::factory()->create(['dev_mode' => true]);

        $this->artisan('llm:toggle-dev-mode', ['client' => (string) $client->id, '--disable' => true])
            ->assertExitCode(0);

        $this->assertFalse($client->fresh()->dev_mode);
    }
}
