<?php

declare(strict_types=1);

namespace Tests\Feature\Sessions;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MemoryToolEndToEndTest extends TestCase
{
    #[Test]
    public function memory_create_command_persists_row(): void
    {
        $this->markTestSkipped('Requires full Sessions facade wiring with memory tool response — deferred to integration phase');
    }
}
