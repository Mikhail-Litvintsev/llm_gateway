<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Responders;

use App\Http\Responders\ErrorResponder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ErrorResponderTest extends TestCase
{
    private ErrorResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->responder = new ErrorResponder;
    }

    #[Test]
    public function invalid_request_returns_400_with_json_body(): void
    {
        $response = $this->responder->invalidRequest('Bad payload', 'req_abc123');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertSame('req_abc123', $response->headers->get('X-Gateway-Request-Id'));
        $this->assertSame(
            ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'Bad payload']],
            json_decode((string) $response->getContent(), true),
        );
    }

    #[Test]
    public function not_found_returns_404(): void
    {
        $response = $this->responder->notFound('not here', 'req_x');

        $this->assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('not_found_error', $body['error']['type']);
    }

    #[Test]
    public function billing_cap_returns_402(): void
    {
        $response = $this->responder->billingCapExceeded('cap', 'req_x');

        $this->assertSame(402, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('billing_error', $body['error']['type']);
    }

    #[Test]
    public function authorization_error_uses_dynamic_status(): void
    {
        $response = $this->responder->authorizationError('permission_error', 'no access', 403, 'req_x');

        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('permission_error', $body['error']['type']);
    }

    #[Test]
    public function rate_limit_returns_429_with_retry_after_header(): void
    {
        $response = $this->responder->rateLimit('throttled', 30, 'req_x');

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('30', $response->headers->get('Retry-After'));
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('rate_limit_error', $body['error']['type']);
    }

    #[Test]
    public function upstream_timeout_returns_504(): void
    {
        $response = $this->responder->upstreamTimeout('timeout', 'req_x');

        $this->assertSame(504, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('upstream_timeout', $body['error']['type']);
    }

    #[Test]
    public function upstream_error_returns_dynamic_status(): void
    {
        $response = $this->responder->upstreamError('boom', 502, 'req_x');

        $this->assertSame(502, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame('api_error', $body['error']['type']);
    }

    #[Test]
    public function all_responses_include_gateway_request_id_header(): void
    {
        foreach (['invalidRequest', 'notFound', 'billingCapExceeded', 'upstreamTimeout'] as $method) {
            $response = $this->responder->{$method}('m', 'req_id_value');
            $this->assertSame('req_id_value', $response->headers->get('X-Gateway-Request-Id'), "method $method");
        }
    }

    #[Test]
    public function all_responses_include_content_type_json(): void
    {
        $response = $this->responder->invalidRequest('m', 'req');
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
    }
}
