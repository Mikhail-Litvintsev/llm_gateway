<?php

declare(strict_types=1);

namespace Tests\Feature\Messages;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ServerToolToolSearchTest extends TestCase
{
    #[Test]
    public function tool_search_with_deferred_custom_tools_accepted(): void
    {
        $this->markTestSkipped('Requires full PayloadBuilder wiring in HTTP context — deferred to integration phase');
    }
}
