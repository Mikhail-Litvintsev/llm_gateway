<?php

declare(strict_types=1);

namespace Tests\Feature\Sessions;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CompactionEndToEndTest extends TestCase
{
    #[Test]
    public function compaction_block_marks_session_and_persists(): void
    {
        $this->markTestSkipped('Requires full Sessions facade wiring with compaction response — deferred to integration phase');
    }
}
