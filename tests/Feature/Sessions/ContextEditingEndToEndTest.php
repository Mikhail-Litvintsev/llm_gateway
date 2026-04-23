<?php

declare(strict_types=1);

namespace Tests\Feature\Sessions;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ContextEditingEndToEndTest extends TestCase
{
    #[Test]
    public function clear_tool_uses_edit_sent_in_payload(): void
    {
        $this->markTestSkipped('Requires full Sessions facade wiring with Http::assertSent — deferred to integration phase');
    }
}
