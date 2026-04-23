<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SecurityHeadersTest extends TestCase
{
    #[Test]
    public function it_sets_headers_from_config(): void
    {
        config(['llm.security_headers' => [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        ]]);

        $middleware = new SecurityHeaders;

        $response = $middleware->handle(
            Request::create('/any'),
            fn (): Response => new Response('ok'),
        );

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame(
            'max-age=31536000; includeSubDomains',
            $response->headers->get('Strict-Transport-Security'),
        );
    }

    #[Test]
    public function it_sets_nothing_when_config_is_empty(): void
    {
        config(['llm.security_headers' => []]);

        $middleware = new SecurityHeaders;

        $response = $middleware->handle(
            Request::create('/any'),
            fn (): Response => new Response('ok'),
        );

        $this->assertNull($response->headers->get('X-Content-Type-Options'));
        $this->assertNull($response->headers->get('X-Frame-Options'));
        $this->assertNull($response->headers->get('Strict-Transport-Security'));
    }

    #[Test]
    public function it_allows_custom_headers_from_config(): void
    {
        config(['llm.security_headers' => [
            'Content-Security-Policy' => "default-src 'self'",
        ]]);

        $middleware = new SecurityHeaders;

        $response = $middleware->handle(
            Request::create('/any'),
            fn (): Response => new Response('ok'),
        );

        $this->assertSame(
            "default-src 'self'",
            $response->headers->get('Content-Security-Policy'),
        );
    }

    #[Test]
    public function middleware_is_actually_registered_in_http_pipeline(): void
    {
        $response = $this->get('/internal/health');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
