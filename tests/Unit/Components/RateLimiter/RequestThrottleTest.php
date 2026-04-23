<?php

namespace Tests\Unit\Components\RateLimiter;

use App\Components\RateLimiter\RequestThrottle;
use Tests\TestCase;

class RequestThrottleTest extends TestCase
{
    private RequestThrottle $throttle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->throttle = app(RequestThrottle::class);
    }

    public function test_allows_request_within_limit(): void
    {
        $result = $this->throttle->attempt(999, 10);

        $this->assertTrue($result->allowed);
        $this->assertEquals(10, $result->limit);
        $this->assertNull($result->retryAfter);
    }

    public function test_blocks_request_exceeding_limit(): void
    {
        $clientId = 998;

        for ($i = 0; $i < 3; $i++) {
            $this->throttle->attempt($clientId, 3);
        }

        $result = $this->throttle->attempt($clientId, 3);

        $this->assertFalse($result->allowed);
        $this->assertEquals(0, $result->remaining);
        $this->assertNotNull($result->retryAfter);
    }

    public function test_returns_remaining_count(): void
    {
        $clientId = 997;

        $result = $this->throttle->attempt($clientId, 5);

        $this->assertTrue($result->allowed);
        $this->assertLessThanOrEqual(5, $result->remaining);
    }
}
