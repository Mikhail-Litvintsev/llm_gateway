<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CorsTest extends TestCase
{
    #[Test]
    public function rejects_cross_origin_when_origin_not_in_allowlist(): void
    {
        config(['cors.allowed_origins' => []]);

        $response = $this->withHeaders([
            'Origin' => 'https://evil.example.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ])->call('OPTIONS', '/v1/messages');

        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function allows_whitelisted_origin(): void
    {
        config(['cors.allowed_origins' => ['https://dashboard.example.com']]);

        $response = $this->withHeaders([
            'Origin' => 'https://dashboard.example.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ])->call('OPTIONS', '/v1/messages');

        $this->assertSame(
            'https://dashboard.example.com',
            $response->headers->get('Access-Control-Allow-Origin'),
        );
    }

    #[Test]
    public function exposes_gateway_headers(): void
    {
        config(['cors.allowed_origins' => ['https://dashboard.example.com']]);

        $response = $this->withHeaders([
            'Origin' => 'https://dashboard.example.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ])->call('OPTIONS', '/v1/messages');

        $exposed = $response->headers->get('Access-Control-Expose-Headers', '');
        foreach (['X-Gateway-Request-Id', 'X-Gateway-Model-Alias', 'X-Gateway-Cost-USD', 'Retry-After'] as $header) {
            $this->assertStringContainsStringIgnoringCase($header, $exposed);
        }
    }
}
