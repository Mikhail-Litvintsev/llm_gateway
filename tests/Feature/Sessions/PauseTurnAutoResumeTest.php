<?php

declare(strict_types=1);

namespace Tests\Feature\Sessions;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PauseTurnAutoResumeTest extends TestCase
{
    #[Test]
    public function auto_resume_true_loops_until_end_turn(): void
    {
        $this->markTestSkipped('Requires full Sessions facade wiring with Http::fake sequence — deferred to integration phase');
    }

    #[Test]
    public function auto_resume_false_returns_pause_turn_to_client(): void
    {
        $this->markTestSkipped('Requires full Sessions facade wiring — deferred to integration phase');
    }
}
