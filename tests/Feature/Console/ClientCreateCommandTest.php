<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ClientCreateCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creates_client_without_features_option(): void
    {
        $this->ensureDefaultWorkspace();

        $this->artisan('client:create', [
            'name' => 'no-features',
            '--model-alias' => 'claude-sonnet',
            '--rate-limit' => 60,
        ])
            ->expectsOutputToContain('Client created')
            ->expectsOutputToContain('gw_live_')
            ->assertSuccessful();

        $client = Client::where('name', 'no-features')->firstOrFail();

        $this->assertSame([], $client->allowed_features);
        $this->assertSame(60, $client->rate_limit_rpm);
        $this->assertSame('claude-sonnet', $client->default_model_alias);
    }

    #[Test]
    public function creates_client_with_features_as_map(): void
    {
        $this->ensureDefaultWorkspace();

        $this->artisan('client:create', [
            'name' => 'with-features',
            '--features' => ['webhook,thinking'],
        ])
            ->assertSuccessful();

        $client = Client::where('name', 'with-features')->firstOrFail();

        $this->assertSame(
            ['webhook' => true, 'thinking' => true],
            $client->allowed_features,
        );
    }

    private function ensureDefaultWorkspace(): void
    {
        ClaudeWorkspace::firstOrCreate(
            ['name' => 'default'],
            [
                'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
                'is_active' => true,
            ],
        );
    }
}
