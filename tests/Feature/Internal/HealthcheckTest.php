<?php

declare(strict_types=1);

namespace Tests\Feature\Internal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class HealthcheckTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function health_returns_ok_from_localhost(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/internal/health');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'components' => [
                'db' => ['status', 'latency_ms', 'error'],
                'redis' => ['status', 'latency_ms', 'error'],
                'anthropic' => ['status', 'latency_ms', 'error'],
            ],
        ]);

        $body = $response->json();
        $this->assertContains($body['status'], ['ok', 'degraded']);
        $this->assertSame('ok', $body['components']['db']['status']);
    }

    #[Test]
    public function health_returns_403_from_external_ip(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->getJson('/internal/health');

        $response->assertStatus(403);
    }

    #[Test]
    public function health_allows_private_network_ip(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->getJson('/internal/health');

        $response->assertStatus(200);
        $response->assertJson(['components' => ['db' => ['status' => 'ok']]]);
    }

    #[Test]
    public function stats_returns_queue_depths(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/internal/stats');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'queues' => ['high', 'default', 'low'],
            'async_pending_counts',
            'top_spenders_month',
        ]);

        $queues = $response->json('queues');
        $this->assertIsInt($queues['high']);
        $this->assertIsInt($queues['default']);
        $this->assertIsInt($queues['low']);
    }

    #[Test]
    public function stats_returns_403_from_external_ip(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->getJson('/internal/stats');

        $response->assertStatus(403);
    }
}
