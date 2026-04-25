<?php

declare(strict_types=1);

namespace Tests\Unit\Components\Claude\Payload\Validators;

use App\Components\Claude\DTO\ThinkingSpec;
use App\Components\Claude\Enums\ThinkingMode;
use App\Components\Claude\Payload\Exceptions\PayloadBuildException;
use App\Components\Claude\Payload\Validators\ThinkingValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ThinkingValidatorTest extends TestCase
{
    private ThinkingValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ThinkingValidator;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $capabilities
     * @param  list<array{code: string, message: string}>  $warnings
     */
    private function validate(
        ThinkingSpec $spec,
        array $payload = [],
        array $capabilities = [],
        string $alias = 'claude-sonnet',
        array &$warnings = [],
    ): void {
        $this->validator->validate($spec, $payload, $capabilities, $alias, $warnings);
    }

    #[Test]
    public function does_nothing_when_thinking_disabled(): void
    {
        $this->validate(new ThinkingSpec(ThinkingMode::Off));
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function passes_on_valid_adaptive_with_effort(): void
    {
        $this->validate(
            new ThinkingSpec(ThinkingMode::Adaptive, effort: 'medium'),
            capabilities: ['supports_adaptive_thinking' => true],
        );
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function throws_on_adaptive_when_model_does_not_support(): void
    {
        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage('Adaptive thinking not supported on claude-haiku');

        $this->validate(
            new ThinkingSpec(ThinkingMode::Adaptive),
            capabilities: ['supports_adaptive_thinking' => false],
            alias: 'claude-haiku',
        );
    }

    #[Test]
    public function throws_on_invalid_effort_value(): void
    {
        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessageMatches("/Invalid thinking effort: 'extreme'/");

        $this->validate(
            new ThinkingSpec(ThinkingMode::Adaptive, effort: 'extreme'),
            capabilities: ['supports_adaptive_thinking' => true],
        );
    }

    #[Test]
    public function passes_on_valid_manual_with_budget_below_max_tokens(): void
    {
        $this->validate(
            new ThinkingSpec(ThinkingMode::Manual, budgetTokens: 1024),
            payload: ['max_tokens' => 4096],
            capabilities: ['supports_thinking' => true],
        );
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function throws_on_manual_when_budget_exceeds_max_tokens_for_non_adaptive_model(): void
    {
        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessageMatches('/budget_tokens \(5000\) must be less than max_tokens \(4096\) on claude-sonnet/');

        $this->validate(
            new ThinkingSpec(ThinkingMode::Manual, budgetTokens: 5000),
            payload: ['max_tokens' => 4096],
            capabilities: ['supports_thinking' => true, 'supports_adaptive_thinking' => false],
        );
    }

    #[Test]
    public function throws_when_top_p_below_0_95_with_thinking(): void
    {
        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessageMatches('/top_p must be within \[0\.95, 1\.0\] when thinking is enabled, got 0\.5/');

        $this->validate(
            new ThinkingSpec(ThinkingMode::Adaptive),
            payload: ['top_p' => 0.5],
            capabilities: ['supports_adaptive_thinking' => true],
        );
    }

    #[Test]
    public function throws_when_top_p_above_1_0_with_thinking(): void
    {
        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessageMatches('/top_p must be within \[0\.95, 1\.0\] when thinking is enabled/');

        $this->validate(
            new ThinkingSpec(ThinkingMode::Adaptive),
            payload: ['top_p' => 1.5],
            capabilities: ['supports_adaptive_thinking' => true],
        );
    }

    #[Test]
    public function throws_when_tool_choice_forced_with_thinking(): void
    {
        $this->expectException(PayloadBuildException::class);
        $this->expectExceptionMessage("tool_choice must be 'auto' or 'none' when thinking is enabled");

        $this->validate(
            new ThinkingSpec(ThinkingMode::Adaptive),
            payload: ['tool_choice' => ['type' => 'any']],
            capabilities: ['supports_adaptive_thinking' => true],
        );
    }

    #[Test]
    public function emits_deprecation_warning_for_manual_on_adaptive_model(): void
    {
        $warnings = [];
        $this->validate(
            new ThinkingSpec(ThinkingMode::Manual, budgetTokens: 1024),
            payload: ['max_tokens' => 4096],
            capabilities: ['supports_thinking' => true, 'supports_adaptive_thinking' => true],
            warnings: $warnings,
        );

        $this->assertCount(1, $warnings);
        $this->assertSame('thinking.manual_deprecated', $warnings[0]['code']);
    }

    #[Test]
    public function build_payload_returns_adaptive_shape(): void
    {
        $payload = $this->validator->buildPayload(new ThinkingSpec(ThinkingMode::Adaptive, effort: 'high'));

        $this->assertSame(['type' => 'adaptive', 'effort' => 'high'], $payload);
    }

    #[Test]
    public function build_payload_returns_manual_shape_with_budget(): void
    {
        $payload = $this->validator->buildPayload(new ThinkingSpec(ThinkingMode::Manual, budgetTokens: 2048));

        $this->assertSame(['type' => 'enabled', 'budget_tokens' => 2048], $payload);
    }
}
