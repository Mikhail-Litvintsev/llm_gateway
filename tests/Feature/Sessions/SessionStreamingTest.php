<?php

declare(strict_types=1);

namespace Tests\Feature\Sessions;

use App\Components\Auth\KeyGenerator;
use App\Components\Auth\KeyHasher;
use App\Models\ClaudeWorkspace;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
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
