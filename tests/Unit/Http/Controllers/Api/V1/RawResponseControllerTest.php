<?php

namespace Tests\Unit\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\RawResponseController;
use ReflectionMethod;
use Tests\TestCase;

class RawResponseControllerTest extends TestCase
{
    private RawResponseController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->controller = new RawResponseController();
    }

    private function callFilterSensitiveHeaders(?array $headers): ?array
    {
        $method = new ReflectionMethod(RawResponseController::class, 'filterSensitiveHeaders');

        return $method->invoke($this->controller, $headers);
    }

    private function callIsValidRequestId(string $requestId): bool
    {
        $method = new ReflectionMethod(RawResponseController::class, 'isValidRequestId');

        return $method->invoke($this->controller, $requestId);
    }

    public function test_filters_authorization_header(): void
    {
        $result = $this->callFilterSensitiveHeaders([
            'Authorization' => 'Bearer secret',
            'content-type' => 'application/json',
        ]);

        $this->assertArrayNotHasKey('Authorization', $result);
        $this->assertArrayHasKey('content-type', $result);
    }

    public function test_filters_x_api_key_header(): void
    {
        $result = $this->callFilterSensitiveHeaders([
            'x-api-key' => 'sk-secret',
            'x-request-id' => 'abc',
        ]);

        $this->assertArrayNotHasKey('x-api-key', $result);
        $this->assertArrayHasKey('x-request-id', $result);
    }

    public function test_filters_api_key_header(): void
    {
        $result = $this->callFilterSensitiveHeaders([
            'Api-Key' => 'secret',
            'content-length' => '123',
        ]);

        $this->assertArrayNotHasKey('Api-Key', $result);
        $this->assertArrayHasKey('content-length', $result);
    }

    public function test_filters_case_insensitively(): void
    {
        $result = $this->callFilterSensitiveHeaders([
            'AUTHORIZATION' => 'Bearer secret',
            'X-API-KEY' => 'secret',
            'API-KEY' => 'secret',
            'safe-header' => 'value',
        ]);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('safe-header', $result);
    }

    public function test_returns_null_for_null_headers(): void
    {
        $this->assertNull($this->callFilterSensitiveHeaders(null));
    }

    public function test_returns_empty_array_for_empty_headers(): void
    {
        $this->assertEquals([], $this->callFilterSensitiveHeaders([]));
    }

    public function test_valid_request_id_formats(): void
    {
        $this->assertTrue($this->callIsValidRequestId('req_001'));
        $this->assertTrue($this->callIsValidRequestId('abc-def'));
        $this->assertTrue($this->callIsValidRequestId('abc:def'));
        $this->assertTrue($this->callIsValidRequestId('abc.def'));
        $this->assertTrue($this->callIsValidRequestId('ABC123'));
    }

    public function test_invalid_request_id_formats(): void
    {
        $this->assertFalse($this->callIsValidRequestId(''));
        $this->assertFalse($this->callIsValidRequestId('req<script>'));
        $this->assertFalse($this->callIsValidRequestId('req id'));
        $this->assertFalse($this->callIsValidRequestId('req/path'));
        $this->assertFalse($this->callIsValidRequestId(str_repeat('a', 257)));
    }
}
