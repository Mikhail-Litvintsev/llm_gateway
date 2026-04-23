<?php

namespace Tests\Unit\Components\RequestPipeline;

use App\Components\RequestPipeline\IdempotencyGuard;
use Tests\TestCase;

class IdempotencyGuardTest extends TestCase
{
    private IdempotencyGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new IdempotencyGuard();
    }

    public function test_check_returns_null_for_unknown_key(): void
    {
        $this->assertNull($this->guard->check('unknown_key', 1));
    }

    public function test_store_and_check_returns_cached_response(): void
    {
        $response = ['status' => 'accepted', 'request_id' => 'req_001'];

        $this->guard->store('test_key', 1, $response);

        $cached = $this->guard->check('test_key', 1);
        $this->assertEquals($response, $cached);
    }

    public function test_keys_are_scoped_to_client(): void
    {
        $response = ['status' => 'accepted', 'request_id' => 'req_001'];

        $this->guard->store('test_key', 1, $response);

        $this->assertNull($this->guard->check('test_key', 2));
    }
}
