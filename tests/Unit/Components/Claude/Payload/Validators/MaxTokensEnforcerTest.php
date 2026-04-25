<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude\Payload\Validators;

use App\Components\Claude\Payload\Exceptions\PayloadBuildException;
use App\Components\Claude\Payload\Validators\MaxTokensEnforcer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MaxTokensEnforcerTest extends TestCase
{
    private MaxTokensEnforcer $enforcer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enforcer = new MaxTokensEnforcer;
    }

    #[Test]
    public function passes_when_max_tokens_below_max_output(): void
    {
        $this->enforcer->enforce(
            ['max_tokens' => 1000],
            ['max_output' => 4096],
            'claude-sonnet',
        );

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function throws_when_max_tokens_exceeds_max_output(): void
    {
        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessageMatches('/max_tokens \(8000\) exceeds model claude-sonnet maximum output \(4096\)/');

        $this->enforcer->enforce(
            ['max_tokens' => 8000],
            ['max_output' => 4096],
            'claude-sonnet',
        );
    }

    #[Test]
    public function passes_when_max_output_missing(): void
    {
        $this->enforcer->enforce(
            ['max_tokens' => 999_999],
            [],
            'claude-sonnet',
        );

        $this->expectNotToPerformAssertions();
    }
}
