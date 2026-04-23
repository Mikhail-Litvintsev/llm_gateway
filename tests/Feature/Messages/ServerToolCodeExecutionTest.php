<?php

declare(strict_types=1);

namespace Tests\Feature\Messages;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ServerToolCodeExecutionTest extends TestCase
{
    #[Test]
    public function code_execution_free_when_combined_with_web_search(): void
    {
        $this->markTestSkipped('Requires workspace_feature_usage table and full billing pipeline — deferred to integration phase');
    }
}
