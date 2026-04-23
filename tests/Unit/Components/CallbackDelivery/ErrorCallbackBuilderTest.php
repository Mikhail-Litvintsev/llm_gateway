<?php

namespace Tests\Unit\Components\CallbackDelivery;

use App\Components\CallbackDelivery\ErrorCallbackBuilder;
use PHPUnit\Framework\TestCase;

class ErrorCallbackBuilderTest extends TestCase
{
    private ErrorCallbackBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new ErrorCallbackBuilder();
    }

    public function test_builds_error_callback_payload(): void
    {
        $result = $this->builder->build(
            ['request_id' => 'req_001'],
            'PROVIDER_TIMEOUT',
            'Request timed out.',
        );

        $this->assertEquals('error', $result['status']);
        $this->assertEquals('req_001', $result['meta']['request_id']);
        $this->assertEquals('PROVIDER_TIMEOUT', $result['error']['code']);
        $this->assertEquals('Request timed out.', $result['error']['message']);
        $this->assertEquals(0, $result['latency_ms']);
    }

    public function test_builds_with_details_and_latency(): void
    {
        $result = $this->builder->build(
            ['request_id' => 'req_002'],
            'CONTEXT_LENGTH_EXCEEDED',
            'Token limit exceeded.',
            ['max_tokens' => 4096],
            500,
        );

        $this->assertEquals(500, $result['latency_ms']);
        $this->assertEquals(['max_tokens' => 4096], $result['error']['details']);
    }

    public function test_empty_details_becomes_stdclass(): void
    {
        $result = $this->builder->build(
            ['request_id' => 'req_003'],
            'ERROR',
            'Something failed.',
        );

        $this->assertInstanceOf(\stdClass::class, $result['error']['details']);
    }
}
