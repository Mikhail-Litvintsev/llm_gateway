<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude\Payload\Validators;

use App\Components\Claude\Payload\Exceptions\PayloadBuildException;
use App\Components\Claude\Payload\Validators\CitationsStructuredOutputEnforcer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CitationsStructuredOutputEnforcerTest extends TestCase
{
    private CitationsStructuredOutputEnforcer $enforcer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enforcer = new CitationsStructuredOutputEnforcer;
    }

    #[Test]
    public function passes_when_neither_set(): void
    {
        $this->enforcer->enforce([]);
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function passes_when_only_citations_enabled(): void
    {
        $this->enforcer->enforce(['citations' => ['enabled' => true]]);
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function passes_when_only_output_format_set(): void
    {
        $this->enforcer->enforce(['output_config' => ['format' => 'json_schema']]);
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function throws_when_both_set(): void
    {
        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage('Citations and structured output formats are mutually exclusive');

        $this->enforcer->enforce([
            'citations' => ['enabled' => true],
            'output_config' => ['format' => 'json_schema'],
        ]);
    }
}
