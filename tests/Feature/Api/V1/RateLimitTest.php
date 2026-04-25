<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::store(config('cache.default'))->flush();
    }

    #[Test]
    public function client_hits_429_after_exceeding_per_client_limit(): void
    {
        [, $plainKey] = $this->seedClient(rateLimitRpm: 5);

        for ($i = 1; $i <= 5; $i++) {
            $this->withToken($plainKey)->getJson('/api/v1/models')->assertOk();
        }

        $response = $this->withToken($plainKey)->getJson('/api/v1/models');

        $response->assertStatus(429);
        $response->assertJsonPath('error.type', 'rate_limit_error');
        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertNotNull($response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    #[Test]
    public function two_clients_have_independent_buckets(): void
    {
        [, $keyA] = $this->seedClient(rateLimitRpm: 5);
        [, $keyB] = $this->seedClient(rateLimitRpm: 5);

        for ($i = 1; $i <= 5; $i++) {
            $this->withToken($keyA)->getJson('/api/v1/models')->assertOk();
        }

        $this->withToken($keyA)->getJson('/api/v1/models')->assertStatus(429);
        $this->withToken($keyB)->getJson('/api/v1/models')->assertOk();
    }

    #[Test]
    public function null_client_rate_limit_falls_back_to_config_default(): void
    {
        config(['llm.rate_limit.default_per_minute' => 3]);

        [, $plainKey] = $this->seedClient(rateLimitRpm: null);

        for ($i = 1; $i <= 3; $i++) {
            $this->withToken($plainKey)->getJson('/api/v1/models')->assertOk();
        }

        $this->withToken($plainKey)->getJson('/api/v1/models')->assertStatus(429);
    }

    /**
     * @return array{0: Client, 1: string}
     */
    private function seedClient(?int $rateLimitRpm): array
    {
        $workspace = ClaudeWorkspace::create([
            'name' => 'rl-ws-'.bin2hex(random_bytes(3)),
            'api_key_encrypted' => Crypt::encryptString('sk-ant-test'),
            'is_active' => true,
        ]);

        $generator = new KeyGenerator;
        $plainKey = $generator->generateRawKey();
        $hasher = $this->app->make(KeyHasher::class);

        $client = Client::create([
            'name' => 'rl-client-'.bin2hex(random_bytes(3)),
            'workspace_id' => $workspace->id,
            'api_key_hash' => $hasher->hash($plainKey),
            'api_key_prefix' => $generator->derivePrefix($plainKey),
            'signing_secret_current_encrypted' => Crypt::encryptString('whsec_secret'),
            'allowed_features' => [],
            'rate_limit_rpm' => $rateLimitRpm,
            'is_dev_mode' => false,
        ]);

        return [$client, $plainKey];
    }
}
