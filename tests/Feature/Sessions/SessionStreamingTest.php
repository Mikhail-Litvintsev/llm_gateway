<?php

declare(strict_types=1);

namespace Tests\Feature\Sessions;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SessionStreamingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function streaming_endpoint_returns_sse_content_type(): void
    {
        $this->markTestSkipped('Requires full streaming infrastructure wiring — deferred to integration phase');
    }
}
